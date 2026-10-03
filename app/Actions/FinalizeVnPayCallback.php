<?php

namespace App\Actions;

use App\Enums\CouponUsageStatus;
use App\Enums\InventoryTransactionType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RefundReason;
use App\Exceptions\VnPayCallbackConflict;
use App\Models\AuditLog;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\PaymentAttempt;
use App\Models\Product;
use App\Models\StockReservation;
use App\Models\User;
use App\ValueObjects\OrderItemSnapshot;
use App\ValueObjects\VnPayCallback;
use App\ValueObjects\VnPayOrderSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;

class FinalizeVnPayCallback
{
    public function __construct(
        private readonly ParsePaymentAttemptSnapshot $snapshots,
        private readonly ReserveCouponUsage $couponCapacity,
        private readonly CreatePendingRefund $refunds,
    ) {}

    /** Returns true for an exact duplicate and false for a newly finalized callback. */
    public function handle(VnPayCallback $callback): bool
    {
        $verifiedAt = CarbonImmutable::now('UTC');
        $fingerprint = $callback->fingerprint();

        return DB::transaction(function () use ($callback, $verifiedAt, $fingerprint): bool {
            $attempt = PaymentAttempt::query()
                ->where('gateway_reference', $callback->reference)
                ->lockForUpdate()
                ->firstOrFail();

            if ($attempt->amount_vnd !== $callback->amountVnd) {
                throw new VnPayCallbackConflict(
                    'Callback amount changed after lookup.', $attempt->id,
                    $attempt->callback_fingerprint, $fingerprint, 'amount_conflict',
                );
            }
            if ($attempt->status !== PaymentStatus::Unpaid) {
                $this->assertExactDuplicate($attempt, $callback, $fingerprint);

                return true;
            }

            $customer = User::query()->whereKey($attempt->user_id)->lockForUpdate()->firstOrFail();

            if (! $callback->succeeded()) {
                $this->finalizeFailure($attempt, $callback, $fingerprint, $verifiedAt);

                return false;
            }

            try {
                $snapshot = $this->snapshots->handle($attempt);
            } catch (InvalidArgumentException $exception) {
                throw new LogicException('Payment Attempt snapshot is corrupt.', 0, $exception);
            }

            $coupon = $attempt->coupon_id === null
                ? null
                : Coupon::query()->whereKey($attempt->coupon_id)->lockForUpdate()->firstOrFail();
            $products = Product::query()
                ->whereKey($snapshot->productIds())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            if ($products->count() !== count($snapshot->productIds())) {
                throw new LogicException('Payment Attempt references missing Products.');
            }

            $cart = $this->lockSnapshotCart($attempt, $snapshot);

            $reservations = StockReservation::query()
                ->where('payment_attempt_id', $attempt->id)
                ->orderBy('product_id')
                ->lockForUpdate()
                ->get();
            $usage = CouponUsage::query()
                ->where('payment_attempt_id', $attempt->id)
                ->lockForUpdate()
                ->first();
            $this->assertResourceShape($attempt, $snapshot, $reservations, $usage);

            $late = $reservations->contains(fn (StockReservation $reservation): bool => ! $reservation->isActiveAt($verifiedAt))
                || ($usage !== null && ! $usage->isHoldingCapacityAt($verifiedAt));
            if ($snapshot->incompleteDiscountAllocation) {
                $this->releaseResources($reservations, $usage, $verifiedAt, 'snapshot_incomplete');
                $this->markPaid($attempt, $callback, $fingerprint, $verifiedAt, $late);
                $this->refunds->handleLocked($attempt, RefundReason::SnapshotIncomplete, $verifiedAt);
                $this->audit($attempt, 'vnpay_callback_refund_pending', [
                    'reason' => RefundReason::SnapshotIncomplete->value,
                    'fingerprint' => $fingerprint,
                ], $verifiedAt);

                return false;
            }

            $refundReason = null;
            if ($late) {
                $this->releaseResources($reservations, $usage, $verifiedAt, 'expired');
                if (! $this->hasLateStock($attempt, $snapshot, $products, $verifiedAt)) {
                    $refundReason = RefundReason::StockUnavailable;
                } elseif ($coupon !== null && ! $this->hasLateCouponCapacity($coupon, $customer, $usage, $verifiedAt)) {
                    $refundReason = RefundReason::CouponCapacityUnavailable;
                }
            }

            if ($refundReason !== null) {
                $this->markPaid($attempt, $callback, $fingerprint, $verifiedAt, true);
                $this->refunds->handleLocked($attempt, $refundReason, $verifiedAt);
                $this->audit($attempt, 'vnpay_callback_refund_pending', [
                    'reason' => $refundReason->value,
                    'fingerprint' => $fingerprint,
                ], $verifiedAt);

                return false;
            }

            $this->markPaid($attempt, $callback, $fingerprint, $verifiedAt, $late);
            $order = $this->createOrder($attempt, $snapshot, $verifiedAt);
            $items = $this->createItems($order, $snapshot);
            $this->createHistory($order, $verifiedAt);
            $this->consumeInventory($order, $items, $products, $verifiedAt);
            if (! $late) {
                foreach ($reservations as $reservation) {
                    $reservation->forceFill(['consumed_at' => $verifiedAt])->save();
                }
            }
            if ($usage !== null) {
                $usage->transitionLifecycle([
                    'status' => CouponUsageStatus::Consumed,
                    'order_id' => $order->id,
                    'consumed_at' => $verifiedAt,
                    'late_callback_exception' => $late,
                ]);
            }
            $this->cleanupCart($attempt, $snapshot, $cart);
            $order->assertItemsReconcile();
            $this->audit($attempt, 'vnpay_callback_order_created', [
                'order_id' => $order->id,
                'late_callback' => $late,
                'fingerprint' => $fingerprint,
            ], $verifiedAt);

            return false;
        }, 3);
    }

    private function finalizeFailure(PaymentAttempt $attempt, VnPayCallback $callback, string $fingerprint, CarbonImmutable $at): void
    {
        if ($attempt->coupon_id !== null) {
            Coupon::query()->whereKey($attempt->coupon_id)->lockForUpdate()->firstOrFail();
        }
        $productIds = StockReservation::query()
            ->where('payment_attempt_id', $attempt->id)
            ->orderBy('product_id')
            ->pluck('product_id')
            ->all();
        Product::query()->whereKey($productIds)->orderBy('id')->lockForUpdate()->get(['id']);
        $reservations = StockReservation::query()
            ->where('payment_attempt_id', $attempt->id)
            ->orderBy('product_id')->lockForUpdate()->get();
        $usage = CouponUsage::query()->where('payment_attempt_id', $attempt->id)->lockForUpdate()->first();

        if (Order::query()->where('payment_attempt_id', $attempt->id)->exists()
            || $attempt->refund()->exists()
            || $reservations->contains(fn (StockReservation $reservation): bool => $reservation->consumed_at !== null)
            || $usage?->status === CouponUsageStatus::Consumed) {
            throw new LogicException('Failed callback conflicts with consumed Payment Attempt resources.');
        }
        $this->releaseResources($reservations, $usage, $at, 'vnpay_payment_failed');
        $attempt->finalizeCallback([
            'status' => PaymentStatus::Failed,
            'gateway_transaction_id' => null,
            'gateway_result_code' => $callback->responseCode,
            'gateway_transaction_status' => $callback->transactionStatus,
            'gateway_paid_at' => $callback->paidAt,
            'gateway_bank_code' => $callback->bankCode,
            'callback_fingerprint' => $fingerprint,
            'verified_at' => $at,
            'late_callback_exception' => false,
        ]);
        $this->audit($attempt, 'vnpay_callback_failed', [
            'result_code' => $callback->responseCode,
            'transaction_status' => $callback->transactionStatus,
            'fingerprint' => $fingerprint,
        ], $at);
    }

    private function assertExactDuplicate(PaymentAttempt $attempt, VnPayCallback $callback, string $fingerprint): void
    {
        $expectedStatus = $callback->succeeded() ? PaymentStatus::Paid : PaymentStatus::Failed;
        $statusMatches = $attempt->status === $expectedStatus
            || ($callback->succeeded() && $attempt->status === PaymentStatus::Refunded);
        $outcomes = (int) Order::query()->where('payment_attempt_id', $attempt->id)->exists()
            + (int) $attempt->refund()->exists();
        $consistentOutcome = $callback->succeeded() ? $outcomes === 1 : $outcomes === 0;

        if (! $statusMatches || ! $consistentOutcome
            || ! is_string($attempt->callback_fingerprint)
            || ! hash_equals($attempt->callback_fingerprint, $fingerprint)
            || $attempt->gateway_result_code !== $callback->responseCode
            || $attempt->gateway_transaction_status !== $callback->transactionStatus
            || $attempt->gateway_transaction_id !== $callback->transactionId
            || $attempt->verified_at === null) {
            throw new VnPayCallbackConflict(
                'Finalized callback evidence conflicts with the new callback.',
                $attempt->id, $attempt->callback_fingerprint, $fingerprint, 'finalized_payload_conflict',
            );
        }
    }

    private function assertResourceShape(PaymentAttempt $attempt, VnPayOrderSnapshot $snapshot, $reservations, ?CouponUsage $usage): void
    {
        $byProduct = $reservations->keyBy('product_id');
        if ($reservations->count() !== count($snapshot->lines)) {
            throw new LogicException('Stock Reservation set does not match the Payment Attempt snapshot.');
        }
        foreach ($snapshot->lines as $line) {
            $reservation = $byProduct->get($line['product_id']);
            if (! $reservation instanceof StockReservation || $reservation->quantity !== $line['quantity']
                || $reservation->consumed_at !== null
                || ! $reservation->expires_at->equalTo($attempt->expires_at)) {
                throw new LogicException('Stock Reservation quantity does not match the Payment Attempt snapshot.');
            }
        }
        if (($attempt->coupon_id === null) !== ($usage === null)
            || ($usage !== null && ($usage->coupon_id !== $attempt->coupon_id
                || $usage->customer_id !== $attempt->user_id
                || $usage->expires_at === null
                || ! $usage->expires_at->equalTo($attempt->expires_at)
                || $usage->status === CouponUsageStatus::Consumed))) {
            throw new LogicException('Coupon Usage does not match the Payment Attempt.');
        }
    }

    private function releaseResources($reservations, ?CouponUsage $usage, CarbonImmutable $at, string $reason): void
    {
        foreach ($reservations as $reservation) {
            if ($reservation->consumed_at !== null) {
                throw new LogicException('Consumed reservation cannot be released.');
            }
            if ($reservation->released_at === null) {
                $reservation->forceFill(['released_at' => $at])->save();
            }
        }
        if ($usage !== null) {
            if ($usage->status === CouponUsageStatus::Consumed) {
                throw new LogicException('Consumed Coupon Usage cannot be released.');
            }
            if ($usage->status === CouponUsageStatus::Reserved) {
                $usage->transitionLifecycle([
                    'status' => CouponUsageStatus::Released,
                    'released_at' => $at,
                    'release_reason' => $reason,
                ]);
            }
        }
    }

    private function hasLateStock(PaymentAttempt $attempt, VnPayOrderSnapshot $snapshot, $products, CarbonImmutable $at): bool
    {
        foreach ($snapshot->lines as $line) {
            $product = $products->get($line['product_id']);
            $reserved = (int) StockReservation::query()
                ->where('product_id', $line['product_id'])
                ->where('payment_attempt_id', '<>', $attempt->id)
                ->activeAt($at)
                ->sum('quantity');
            if (! $product instanceof Product || $product->sellable_quantity < $reserved
                || $product->sellable_quantity - $reserved < $line['quantity']) {
                return false;
            }
        }

        return true;
    }

    private function hasLateCouponCapacity(Coupon $coupon, User $customer, ?CouponUsage $usage, CarbonImmutable $at): bool
    {
        if ($usage === null || $usage->status !== CouponUsageStatus::Released) {
            throw new LogicException('Late callback Coupon Usage must be released before capacity checking.');
        }
        try {
            $this->couponCapacity->assertCapacityLocked($coupon, $customer, $at, $usage->id);

            return true;
        } catch (ValidationException) {
            return false;
        }
    }

    private function markPaid(PaymentAttempt $attempt, VnPayCallback $callback, string $fingerprint, CarbonImmutable $at, bool $late): void
    {
        $attempt->finalizeCallback([
            'status' => PaymentStatus::Paid,
            'gateway_transaction_id' => $callback->transactionId,
            'gateway_result_code' => $callback->responseCode,
            'gateway_transaction_status' => $callback->transactionStatus,
            'gateway_paid_at' => $callback->paidAt,
            'gateway_bank_code' => $callback->bankCode,
            'callback_fingerprint' => $fingerprint,
            'verified_at' => $at,
            'late_callback_exception' => $late,
        ]);
    }

    private function createOrder(PaymentAttempt $attempt, VnPayOrderSnapshot $snapshot, CarbonImmutable $at): Order
    {
        $order = new Order;
        $order->forceFill(array_merge($snapshot->recipient, [
            'user_id' => $attempt->user_id,
            'payment_attempt_id' => $attempt->id,
            'request_key' => null,
            'idempotency_fingerprint' => null,
            'order_code' => 'ORD-VNP-'.$attempt->id,
            'status' => OrderStatus::Placed,
            'payment_method' => PaymentMethod::VnPay,
            'payment_status' => PaymentStatus::Paid,
            'coupon_id' => $attempt->coupon_id,
            'coupon_snapshot_json' => $snapshot->coupon,
            'items_subtotal_vnd' => $snapshot->pricing['cart_subtotal_vnd'],
            'item_discount_vnd' => $snapshot->pricing['item_discount_vnd'],
            'shipping_fee_vnd' => $snapshot->pricing['shipping_fee_vnd'],
            'shipping_discount_vnd' => $snapshot->pricing['shipping_discount_vnd'],
            'total_vnd' => $snapshot->pricing['total_vnd'],
            'delivered_at' => null,
            'created_at' => $at,
            'updated_at' => $at,
        ]))->save();

        return $order;
    }

    /** @return list<OrderItem> */
    private function createItems(Order $order, VnPayOrderSnapshot $snapshot): array
    {
        $items = [];
        foreach ($snapshot->lines as $line) {
            if (! is_int($line['discount_vnd']) || ! is_int($line['line_total_vnd'])) {
                throw new LogicException('Payment Attempt line allocation is unavailable.');
            }
            $value = new OrderItemSnapshot(
                $line['product_name'], $line['sku'], $line['quantity'], $line['unit_price_vnd'],
                $line['line_subtotal_vnd'], $line['discount_vnd'], $line['line_total_vnd'],
            );
            $item = new OrderItem;
            $item->forceFill([
                'order_id' => $order->id, 'product_id' => $line['product_id'],
                'product_name' => $value->productName, 'sku' => $value->sku,
                'quantity' => $value->quantity, 'unit_price_vnd' => $value->unitPriceVnd,
                'line_subtotal_vnd' => $value->lineSubtotalVnd, 'discount_vnd' => $value->discountVnd,
                'line_total_vnd' => $value->lineTotalVnd,
            ])->save();
            $items[] = $item;
        }

        return $items;
    }

    private function createHistory(Order $order, CarbonImmutable $at): void
    {
        $history = new OrderStatusHistory;
        $history->forceFill([
            'order_id' => $order->id, 'from_status' => null, 'to_status' => OrderStatus::Placed,
            'actor_id' => null, 'reason' => 'VNPay xác nhận thanh toán thành công.',
            'event_key' => (string) Str::uuid(), 'created_at' => $at,
        ])->save();
    }

    private function consumeInventory(Order $order, array $items, $products, CarbonImmutable $at): void
    {
        foreach ($items as $item) {
            $product = $products->get($item->product_id);
            if (! $product instanceof Product || $product->sellable_quantity < $item->quantity) {
                throw new LogicException('Locked Product stock is insufficient during finalization.');
            }
            $product->forceFill(['sellable_quantity' => $product->sellable_quantity - $item->quantity])->save();
            $ledger = new InventoryTransaction;
            $ledger->forceFill([
                'product_id' => $product->id, 'type' => InventoryTransactionType::Sale,
                'sellable_delta' => -$item->quantity, 'damaged_delta' => 0,
                'source_key' => 'order-item:'.$item->id.':sale', 'adjustment_request_id' => null,
                'order_item_id' => $item->id, 'return_inspection_id' => null, 'actor_id' => null,
                'reason' => 'Xuất kho cho đơn '.$order->order_code, 'created_at' => $at,
            ])->save();
        }
    }

    private function lockSnapshotCart(PaymentAttempt $attempt, VnPayOrderSnapshot $snapshot)
    {
        $ids = array_values(array_filter(array_map(fn (array $line): ?int => $line['cart_item_id'], $snapshot->lines)));
        if ($ids === []) {
            return collect();
        }

        return CartItem::query()->where('user_id', $attempt->user_id)
            ->whereKey($ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
    }

    private function cleanupCart(PaymentAttempt $attempt, VnPayOrderSnapshot $snapshot, $cart): void
    {
        foreach ($snapshot->lines as $line) {
            if ($line['cart_item_id'] === null) {
                continue;
            }
            $item = $cart->get($line['cart_item_id']);
            if ($item instanceof CartItem
                && $item->product_id === $line['product_id']
                && $item->quantity === $line['quantity']
                && $item->updated_at->clone()->utc()->format('Y-m-d\TH:i:s.u\Z') === $line['cart_item_updated_at']) {
                $item->delete();
            }
        }
    }

    private function audit(PaymentAttempt $attempt, string $action, array $after, CarbonImmutable $at): void
    {
        $audit = new AuditLog;
        $audit->forceFill([
            'actor_id' => null, 'action' => $action, 'subject_type' => PaymentAttempt::class,
            'subject_id' => $attempt->id, 'before_json' => null, 'after_json' => $after,
            'request_id' => null, 'created_at' => $at,
        ])->save();
    }
}
