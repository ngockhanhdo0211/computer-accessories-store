<?php

namespace App\Actions;

use App\Enums\CouponUsageStatus;
use App\Enums\InventoryTransactionType;
use App\Enums\OrderCancellationRequestStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RefundReason;
use App\Enums\RefundStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\OrderCancellationRequest;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\PaymentAttempt;
use App\Models\Product;
use App\Models\Refund;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReviewOrderCancellationRequest
{
    private const MAX_QUANTITY = 4_294_967_295;

    public function __construct(private readonly CreatePendingRefund $refunds) {}

    public function handle(OrderCancellationRequest $request, User $actor, mixed $eventKey, mixed $decision, mixed $note): OrderCancellationRequest
    {
        $eventKey = is_string($eventKey) ? trim($eventKey) : $eventKey;
        $note = is_string($note) && trim($note) === '' ? null : (is_string($note) ? trim($note) : $note);
        $validated = Validator::make(['event_key' => $eventKey, 'decision' => $decision, 'note' => $note], [
            'event_key' => ['required', 'uuid'], 'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'note' => [$decision === 'rejected' ? 'required' : 'nullable', 'string', 'max:500', 'not_regex:/[<>\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u'],
        ])->validate();
        $fingerprint = hash('sha256', json_encode(['request_id' => $request->id, 'actor_id' => $actor->id, ...$validated], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        try {
            return DB::transaction(function () use ($request, $actor, $validated, $fingerprint): OrderCancellationRequest {
                $reviewer = User::query()->lockForUpdate()->find($actor->id);
                $role = UserRole::tryFrom((string) $reviewer?->getRawOriginal('role'));
                if ($reviewer === null || ! in_array($role, [UserRole::Employee, UserRole::Admin], true)
                    || UserStatus::tryFrom((string) $reviewer->getRawOriginal('status')) !== UserStatus::Active) {
                    throw ValidationException::withMessages(['authorization' => 'Tài khoản không có quyền xử lý yêu cầu hủy.']);
                }
                // Every cancellation writer locks actor -> order -> request. Keeping
                // one order prevents transition/review races from deadlocking.
                $order = Order::query()->lockForUpdate()->findOrFail($request->order_id);
                $locked = OrderCancellationRequest::query()->lockForUpdate()->findOrFail($request->id);
                if ($locked->order_id !== $order->id) {
                    throw ValidationException::withMessages(['request' => 'Yêu cầu hủy không còn khớp Order.']);
                }
                if ($locked->review_event_key !== null) {
                    if ($locked->review_event_key !== $validated['event_key'] || $locked->review_fingerprint !== $fingerprint) {
                        throw ValidationException::withMessages(['event_key' => 'Yêu cầu đã được xử lý bằng nội dung khác.']);
                    }

                    $this->assertReplayEvidence($locked, $order, $reviewer, $validated);

                    return $locked;
                }
                if ($locked->status !== OrderCancellationRequestStatus::Pending) {
                    throw ValidationException::withMessages(['request' => 'Yêu cầu hủy đã được xử lý.']);
                }
                $now = CarbonImmutable::now('UTC');
                $target = OrderCancellationRequestStatus::from($validated['decision']);
                if ($target === OrderCancellationRequestStatus::Rejected) {
                    $locked->transitionReview(['status' => $target, 'reviewed_by' => $reviewer->id, 'review_note' => $validated['note'],
                        'reviewed_at' => $now, 'review_event_key' => $validated['event_key'], 'review_fingerprint' => $fingerprint]);
                    $this->audit($locked, $reviewer, $validated['event_key'], 'rejected', $validated['note'], $now);

                    return $locked->fresh();
                }
                if ($order->status !== OrderStatus::Placed || $order->delivered_at !== null || $order->user_id !== $locked->customer_id) {
                    throw ValidationException::withMessages(['order' => 'Order không còn đủ điều kiện chấp thuận hủy.']);
                }
                $latestHistory = OrderStatusHistory::query()->where('order_id', $order->id)
                    ->latest('created_at')->latest('id')->lockForUpdate()->first();
                if ($latestHistory === null || $latestHistory->to_status !== OrderStatus::Placed) {
                    throw ValidationException::withMessages(['order' => 'Lịch sử trạng thái không khớp Order. Cần đối soát trước khi xử lý.']);
                }

                $attempt = $order->payment_attempt_id === null ? null : PaymentAttempt::query()->lockForUpdate()->findOrFail($order->payment_attempt_id);
                $coupon = $order->coupon_id === null ? null : Coupon::query()->lockForUpdate()->findOrFail($order->coupon_id);
                $items = OrderItem::query()->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();
                if ($items->isEmpty()) {
                    throw ValidationException::withMessages(['order' => 'Order không có sản phẩm để hoàn kho.']);
                }
                $products = Product::query()->whereIn('id', $items->pluck('product_id')->unique()->sort())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $usage = CouponUsage::query()->where('order_id', $order->id)->lockForUpdate()->first();
                if (InventoryTransaction::query()->whereIn('order_item_id', $items->pluck('id'))
                    ->where('type', InventoryTransactionType::CancelRestore)->lockForUpdate()->exists()) {
                    throw ValidationException::withMessages(['order' => 'Order đã có dữ liệu hoàn kho cần đối soát.']);
                }

                $locked->transitionReview(['status' => $target, 'reviewed_by' => $reviewer->id, 'review_note' => $validated['note'],
                    'reviewed_at' => $now, 'review_event_key' => $validated['event_key'], 'review_fingerprint' => $fingerprint]);
                foreach ($items as $item) {
                    $product = $products->get($item->product_id);
                    if (! $product instanceof Product || $product->sellable_quantity > self::MAX_QUANTITY - $item->quantity) {
                        throw ValidationException::withMessages(['inventory' => 'Không thể hoàn kho an toàn cho sản phẩm trong Order.']);
                    }
                    $sold = $product->sold_quantity;
                    $product->forceFill(['sellable_quantity' => $product->sellable_quantity + $item->quantity])->save();
                    if ($product->sold_quantity !== $sold) {
                        throw new \LogicException('Cancellation must not change sold quantity.');
                    }
                    (new InventoryTransaction)->forceFill([
                        'product_id' => $product->id, 'type' => InventoryTransactionType::CancelRestore,
                        'sellable_delta' => $item->quantity, 'damaged_delta' => 0,
                        'source_key' => 'order-item:'.$item->id.':cancel-restore', 'adjustment_request_id' => null,
                        'order_item_id' => $item->id, 'return_inspection_id' => null,
                        'order_cancellation_request_id' => $locked->id, 'actor_id' => $reviewer->id,
                        'reason' => $locked->reason, 'created_at' => $now,
                    ])->save();
                }
                $from = $order->status;
                $order->forceFill(['status' => OrderStatus::Cancelled])->save();
                if ($order->payment_method === PaymentMethod::CashOnDelivery) {
                    if ($order->payment_status !== PaymentStatus::Unpaid || $attempt !== null) {
                        throw ValidationException::withMessages(['payment' => 'Trạng thái COD không hợp lệ.']);
                    }
                    $this->releaseCodCoupon($order, $coupon, $usage, $now);
                } elseif ($order->payment_method === PaymentMethod::VnPay) {
                    if ($attempt === null || $attempt->status !== PaymentStatus::Paid || $order->payment_status !== PaymentStatus::Paid
                        || $attempt->user_id !== $order->user_id || $attempt->amount_vnd !== $order->total_vnd) {
                        throw ValidationException::withMessages(['payment' => 'Payment Attempt VNPay không hợp lệ.']);
                    }
                    $this->assertVnPayCouponState($order, $attempt, $coupon, $usage);
                    $this->refunds->handleCancelledOrderLocked($attempt, $order, RefundReason::CustomerCancellation, $now);
                } else {
                    throw ValidationException::withMessages(['payment' => 'Phương thức thanh toán không hỗ trợ.']);
                }

                (new OrderStatusHistory)->forceFill(['order_id' => $order->id, 'from_status' => $from, 'to_status' => OrderStatus::Cancelled,
                    'actor_id' => $reviewer->id, 'reason' => $locked->reason, 'event_key' => $validated['event_key'], 'created_at' => $now])->save();
                $this->audit($locked, $reviewer, $validated['event_key'], 'approved', $validated['note'], $now);

                return $locked->fresh();
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            if (str_contains($exception->getMessage(), 'review_event')) {
                throw ValidationException::withMessages(['event_key' => 'Mã chống lặp đã được dùng cho yêu cầu khác.']);
            }

            throw $exception;
        }
    }

    private function releaseCodCoupon(Order $order, ?Coupon $coupon, ?CouponUsage $usage, CarbonImmutable $at): void
    {
        if ($order->coupon_id === null) {
            if ($usage !== null) {
                throw ValidationException::withMessages(['coupon_usage' => 'Order không dùng Coupon.']);
            }

            return;
        }
        if ($coupon === null || $usage === null || $usage->coupon_id !== $coupon->id || $usage->customer_id !== $order->user_id
            || $usage->payment_attempt_id !== null || $usage->status !== CouponUsageStatus::Consumed) {
            throw ValidationException::withMessages(['coupon_usage' => 'Coupon Usage COD không hợp lệ.']);
        }
        $usage->transitionLifecycle(['status' => CouponUsageStatus::Released, 'released_at' => $at, 'release_reason' => 'customer_cancellation_approved']);
    }

    private function assertVnPayCouponState(Order $order, PaymentAttempt $attempt, ?Coupon $coupon, ?CouponUsage $usage): void
    {
        if ($order->coupon_id === null) {
            if ($attempt->coupon_id !== null || $usage !== null) {
                throw ValidationException::withMessages(['coupon_usage' => 'Order VNPay không dùng Coupon nhưng có dữ liệu Coupon không khớp.']);
            }

            return;
        }
        if ($coupon === null || $attempt->coupon_id !== $coupon->id || $usage === null
            || $usage->coupon_id !== $coupon->id || $usage->customer_id !== $order->user_id
            || $usage->payment_attempt_id !== $attempt->id || $usage->order_id !== $order->id
            || $usage->status !== CouponUsageStatus::Consumed) {
            throw ValidationException::withMessages(['coupon_usage' => 'Coupon Usage VNPay không khớp hoặc không còn consumed.']);
        }
    }

    private function audit(OrderCancellationRequest $request, User $actor, string $eventKey, string $decision, ?string $note, CarbonImmutable $at): void
    {
        (new AuditLog)->forceFill(['actor_id' => $actor->id, 'action' => 'order.cancellation_'.$decision,
            'subject_type' => OrderCancellationRequest::class, 'subject_id' => $request->id,
            'before_json' => ['status' => 'pending'], 'after_json' => ['status' => $decision, 'note' => $note],
            'request_id' => $eventKey, 'created_at' => $at])->save();
    }

    private function assertReplayEvidence(OrderCancellationRequest $request, Order $order, User $reviewer, array $validated): void
    {
        $decision = OrderCancellationRequestStatus::from($validated['decision']);
        if ($request->status !== $decision || $request->reviewed_by !== $reviewer->id
            || $request->review_note !== $validated['note'] || $request->reviewed_at === null) {
            throw ValidationException::withMessages(['request' => 'Báº±ng chá»©ng xá»­ lý yêu cáº§u há»§y không còn khá»›p. Cáº§n Ä‘á»‘i soát.']);
        }

        $audits = AuditLog::query()
            ->where('subject_type', OrderCancellationRequest::class)
            ->where('subject_id', $request->id)
            ->where('request_id', $validated['event_key'])
            ->get();
        $expectedAction = 'order.cancellation_'.$validated['decision'];
        if ($audits->count() !== 1 || $audits->first()->actor_id !== $reviewer->id
            || $audits->first()->action !== $expectedAction
            || $audits->first()->before_json !== ['status' => 'pending']
            || $audits->first()->after_json !== ['status' => $validated['decision'], 'note' => $validated['note']]) {
            throw ValidationException::withMessages(['audit' => 'Audit cá»§a láº§n xá»­ lý không Ä‘áº§y Ä‘á»§ hoáº·c không khá»›p. Cáº§n Ä‘á»‘i soát.']);
        }

        $histories = OrderStatusHistory::query()->where('order_id', $order->id)
            ->where('event_key', $validated['event_key'])->get();
        if ($decision === OrderCancellationRequestStatus::Rejected) {
            if ($histories->isNotEmpty()) {
                throw ValidationException::withMessages(['order' => 'Yêu cáº§u tá»« chá»‘i có Order History không há»£p lá»‡. Cáº§n Ä‘á»‘i soát.']);
            }

            return;
        }

        $history = $histories->first();
        if ($order->status !== OrderStatus::Cancelled || $histories->count() !== 1 || $history === null
            || $history->from_status !== OrderStatus::Placed || $history->to_status !== OrderStatus::Cancelled
            || $history->actor_id !== $reviewer->id || $history->reason !== $request->reason) {
            throw ValidationException::withMessages(['order' => 'Order hoáº·c lá»‹ch sá»­ há»§y không còn khá»›p. Cáº§n Ä‘á»‘i soát.']);
        }

        $items = OrderItem::query()->where('order_id', $order->id)->orderBy('id')->get();
        $ledgers = InventoryTransaction::query()->where('order_cancellation_request_id', $request->id)
            ->where('type', InventoryTransactionType::CancelRestore)->get()->keyBy('order_item_id');
        if ($items->isEmpty() || $ledgers->count() !== $items->count()) {
            throw ValidationException::withMessages(['inventory' => 'Báº±ng chá»©ng hoàn kho không Ä‘áº§y Ä‘á»§. Cáº§n Ä‘á»‘i soát.']);
        }
        foreach ($items as $item) {
            $ledger = $ledgers->get($item->id);
            if (! $ledger instanceof InventoryTransaction || $ledger->product_id !== $item->product_id
                || $ledger->sellable_delta !== $item->quantity || $ledger->damaged_delta !== 0
                || $ledger->return_inspection_id !== null || $ledger->actor_id !== $reviewer->id) {
                throw ValidationException::withMessages(['inventory' => 'Báº±ng chá»©ng hoàn kho không khá»›p Order Item. Cáº§n Ä‘á»‘i soát.']);
            }
        }

        $usage = CouponUsage::query()->where('order_id', $order->id)->first();
        $refunds = Refund::query()->where('order_id', $order->id)->get();
        if ($order->payment_method === PaymentMethod::CashOnDelivery) {
            if ($order->payment_status !== PaymentStatus::Unpaid || $order->payment_attempt_id !== null || $refunds->isNotEmpty()) {
                throw ValidationException::withMessages(['payment' => 'Báº±ng chá»©ng thanh toán COD không khá»›p. Cáº§n Ä‘á»‘i soát.']);
            }
            if (($order->coupon_id === null && $usage !== null)
                || ($order->coupon_id !== null && ($usage === null || $usage->coupon_id !== $order->coupon_id
                    || $usage->customer_id !== $order->user_id || $usage->payment_attempt_id !== null
                    || $usage->status !== CouponUsageStatus::Released))) {
                throw ValidationException::withMessages(['coupon_usage' => 'Báº±ng chá»©ng Coupon COD không khá»›p. Cáº§n Ä‘á»‘i soát.']);
            }

            return;
        }

        $attempt = $order->payment_attempt_id === null ? null : PaymentAttempt::query()->find($order->payment_attempt_id);
        $refund = $refunds->first();
        if ($order->payment_method !== PaymentMethod::VnPay || ! $attempt instanceof PaymentAttempt
            || $attempt->user_id !== $order->user_id || $attempt->amount_vnd !== $order->total_vnd
            || $refunds->count() !== 1 || ! $refund instanceof Refund
            || $refund->payment_attempt_id !== $attempt->id || $refund->amount_vnd !== $order->total_vnd
            || $refund->reason !== RefundReason::CustomerCancellation) {
            throw ValidationException::withMessages(['refund' => 'Báº±ng chá»©ng Refund VNPay không khá»›p. Cáº§n Ä‘á»‘i soát.']);
        }
        $refundSucceeded = $refund->status === RefundStatus::Succeeded;
        if (($refundSucceeded && ($attempt->status !== PaymentStatus::Refunded || $order->payment_status !== PaymentStatus::Refunded))
            || (! $refundSucceeded && ($attempt->status !== PaymentStatus::Paid || $order->payment_status !== PaymentStatus::Paid))) {
            throw ValidationException::withMessages(['payment' => 'Tráº¡ng thái thanh toán không khá»›p Refund. Cáº§n Ä‘á»‘i soát.']);
        }
        if (($order->coupon_id === null && ($attempt->coupon_id !== null || $usage !== null))
            || ($order->coupon_id !== null && ($attempt->coupon_id !== $order->coupon_id || $usage === null
                || $usage->payment_attempt_id !== $attempt->id || $usage->customer_id !== $order->user_id
                || $usage->status !== ($refundSucceeded ? CouponUsageStatus::Released : CouponUsageStatus::Consumed)))) {
            throw ValidationException::withMessages(['coupon_usage' => 'Báº±ng chá»©ng Coupon VNPay không khá»›p. Cáº§n Ä‘á»‘i soát.']);
        }
    }
}
