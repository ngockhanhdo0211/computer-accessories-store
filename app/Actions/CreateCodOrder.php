<?php

namespace App\Actions;

use App\Enums\CouponUsageStatus;
use App\Enums\InventoryTransactionType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\User;
use App\ValueObjects\CheckoutQuote;
use App\ValueObjects\CheckoutQuoteLine;
use App\ValueObjects\CheckoutRecipient;
use App\ValueObjects\OrderItemSnapshot;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateCodOrder
{
    public function __construct(
        private readonly BuildCheckoutQuote $quotes,
        private readonly BuildCodOrderFingerprint $fingerprints,
    ) {}

    public function handle(
        User $customer,
        CheckoutRecipient $recipient,
        string $requestKey,
        ?string $couponCode,
        string $expectedFingerprint,
        ?CarbonInterface $at = null,
    ): Order {
        $this->assertActiveCustomer($customer);
        $requestKey = trim($requestKey);
        $couponCode = $couponCode === null ? null : Str::upper(trim($couponCode));
        $couponCode = $couponCode === '' ? null : $couponCode;

        if (! Str::isUuid($requestKey) || strlen($requestKey) !== 36) {
            throw ValidationException::withMessages(['request_key' => 'Khóa chống tạo đơn trùng không hợp lệ.']);
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $expectedFingerprint) !== 1) {
            throw ValidationException::withMessages(['request_key' => 'Báo giá xác nhận không hợp lệ. Vui lòng tạo lại báo giá.']);
        }

        $createdAt = CarbonImmutable::instance($at ?? now())->utc();
        $initialProductIds = $this->cartProductIds($customer);

        try {
            return $this->runTransaction(function () use ($customer, $recipient, $requestKey, $couponCode, $expectedFingerprint, $createdAt, $initialProductIds): Order {
                $freshCustomer = User::query()->findOrFail($customer->id);
                $this->assertActiveCustomer($freshCustomer);

                $existing = $this->findExisting($freshCustomer, $requestKey);
                if ($existing !== null) {
                    return $this->replay($existing, $recipient, $couponCode, $expectedFingerprint);
                }

                $coupon = $couponCode === null ? null : Coupon::query()
                    ->where('code', $couponCode)
                    ->lockForUpdate()
                    ->first();
                if ($couponCode !== null && $coupon === null) {
                    throw ValidationException::withMessages(['coupon_code' => 'Mã giảm giá không tồn tại.']);
                }

                $lockedProducts = Product::query()
                    ->whereKey($initialProductIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');
                $lockedItems = CartItem::query()
                    ->where('user_id', $freshCustomer->id)
                    ->orderBy('product_id')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get(['id', 'product_id', 'quantity']);

                $existing = $this->findExisting($freshCustomer, $requestKey);
                if ($existing !== null) {
                    return $this->replay($existing, $recipient, $couponCode, $expectedFingerprint);
                }

                $lockedProductIds = $lockedItems->pluck('product_id')
                    ->map(fn ($id): int => (int) $id)
                    ->unique()
                    ->sort()
                    ->values()
                    ->all();

                if ($initialProductIds !== $lockedProductIds || $lockedProducts->count() !== count($initialProductIds)) {
                    throw ValidationException::withMessages([
                        'cart' => 'Giỏ hàng vừa thay đổi. Vui lòng kiểm tra và tạo lại báo giá.',
                    ]);
                }

                $quote = $this->quotes->handle($freshCustomer, $recipient, $couponCode, $createdAt, true);
                $fingerprint = $this->fingerprints->handle($freshCustomer, $requestKey, $quote, $coupon);

                if (! hash_equals($expectedFingerprint, $fingerprint['fingerprint'])) {
                    throw ValidationException::withMessages([
                        'request_key' => 'Giỏ hàng, giá, phí vận chuyển hoặc mã giảm giá đã thay đổi. Vui lòng tạo lại báo giá.',
                    ]);
                }

                if ($coupon !== null) {
                    $this->assertCouponCapacityLocked($coupon, $freshCustomer, $createdAt);
                }

                $order = $this->createOrder($freshCustomer, $recipient, $requestKey, $expectedFingerprint, $quote, $fingerprint['coupon_snapshot'], $createdAt);
                $orderItems = $this->createItems($order, $quote->lines, $fingerprint['discounts']);
                $this->createInitialHistory($order, $freshCustomer, $createdAt);
                $this->decrementInventory($order, $orderItems, $lockedProducts, $freshCustomer, $createdAt);

                if ($coupon !== null) {
                    $this->consumeCoupon($coupon, $freshCustomer, $order, $createdAt);
                }

                CartItem::query()
                    ->where('user_id', $freshCustomer->id)
                    ->whereKey($lockedItems->pluck('id')->all())
                    ->delete();
                $order->assertItemsReconcile();

                return $this->loadReceipt($order);
            });
        } catch (QueryException $exception) {
            if (! $this->isRequestKeyDuplicate($exception)) {
                throw $exception;
            }

            $existing = Order::query()
                ->where('user_id', $customer->id)
                ->where('request_key', $requestKey)
                ->first();
            if ($existing === null) {
                throw $exception;
            }

            return $this->replay($existing, $recipient, $couponCode, $expectedFingerprint);
        }
    }

    private function createOrder(
        User $customer,
        CheckoutRecipient $recipient,
        string $requestKey,
        string $fingerprint,
        CheckoutQuote $quote,
        ?array $couponSnapshot,
        CarbonInterface $createdAt,
    ): Order {
        $order = new Order;
        $order->forceFill(array_merge($recipient->snapshot(), [
            'user_id' => $customer->id,
            'payment_attempt_id' => null,
            'request_key' => $requestKey,
            'idempotency_fingerprint' => $fingerprint,
            'order_code' => 'ORD-'.$createdAt->format('Ymd').'-'.Str::upper(Str::random(12)),
            'status' => OrderStatus::Placed,
            'payment_method' => PaymentMethod::CashOnDelivery,
            'payment_status' => PaymentStatus::Unpaid,
            'coupon_id' => $quote->coupon?->couponId,
            'coupon_snapshot_json' => $couponSnapshot,
            'items_subtotal_vnd' => $quote->cartSubtotalVnd,
            'item_discount_vnd' => $quote->productDiscountVnd,
            'shipping_fee_vnd' => $quote->shippingFeeVnd,
            'shipping_discount_vnd' => $quote->shippingDiscountVnd,
            'total_vnd' => $quote->grandTotalVnd,
            'delivered_at' => null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]))->save();

        return $order;
    }

    /**
     * @param  list<CheckoutQuoteLine>  $lines
     * @param  array<int, int>  $discounts
     * @return list<OrderItem>
     */
    private function createItems(Order $order, array $lines, array $discounts): array
    {
        usort($lines, fn (CheckoutQuoteLine $left, CheckoutQuoteLine $right): int => $left->productId <=> $right->productId);
        $items = [];

        foreach ($lines as $line) {
            $snapshot = new OrderItemSnapshot(
                $line->productName,
                $line->sku,
                $line->quantity,
                $line->unitPriceVnd,
                $line->lineSubtotalVnd,
                $discounts[$line->productId],
                $line->lineSubtotalVnd - $discounts[$line->productId],
            );
            $item = new OrderItem;
            $item->forceFill([
                'order_id' => $order->id,
                'product_id' => $line->productId,
                'product_name' => $snapshot->productName,
                'sku' => $snapshot->sku,
                'quantity' => $snapshot->quantity,
                'unit_price_vnd' => $snapshot->unitPriceVnd,
                'line_subtotal_vnd' => $snapshot->lineSubtotalVnd,
                'discount_vnd' => $snapshot->discountVnd,
                'line_total_vnd' => $snapshot->lineTotalVnd,
            ])->save();
            $items[] = $item;
        }

        return $items;
    }

    private function createInitialHistory(Order $order, User $customer, CarbonInterface $createdAt): void
    {
        $history = new OrderStatusHistory;
        $history->forceFill([
            'order_id' => $order->id,
            'from_status' => null,
            'to_status' => OrderStatus::Placed,
            'actor_id' => $customer->id,
            'reason' => 'Khách hàng đặt đơn thanh toán khi nhận hàng.',
            'event_key' => (string) Str::uuid(),
            'created_at' => $createdAt,
        ])->save();
    }

    /** @param list<OrderItem> $items */
    private function decrementInventory(Order $order, array $items, $lockedProducts, User $customer, CarbonInterface $createdAt): void
    {
        foreach ($items as $item) {
            $product = $lockedProducts->get($item->product_id);
            if (! $product instanceof Product || $product->sellable_quantity < $item->quantity) {
                throw ValidationException::withMessages(['cart' => 'Tồn kho không còn đủ để hoàn tất đơn hàng.']);
            }

            $product->forceFill(['sellable_quantity' => $product->sellable_quantity - $item->quantity])->save();
            $this->createSaleLedger($item, $product, $order->order_code, $customer, $createdAt);
        }
    }

    private function createSaleLedger(OrderItem $item, Product $product, string $orderCode, User $customer, CarbonInterface $createdAt): void
    {
        $ledger = new InventoryTransaction;
        $ledger->forceFill([
            'product_id' => $product->id,
            'type' => InventoryTransactionType::Sale,
            'sellable_delta' => -$item->quantity,
            'damaged_delta' => 0,
            'source_key' => 'order-item:'.$item->id.':sale',
            'adjustment_request_id' => null,
            'order_item_id' => $item->id,
            'actor_id' => $customer->id,
            'reason' => 'Xuất kho cho đơn '.$orderCode,
            'created_at' => $createdAt,
        ])->save();
    }

    private function consumeCoupon(Coupon $coupon, User $customer, Order $order, CarbonInterface $createdAt): void
    {
        $usage = new CouponUsage;
        $usage->forceFill([
            'coupon_id' => $coupon->id,
            'customer_id' => $customer->id,
            'payment_attempt_id' => null,
            'order_id' => $order->id,
            'status' => CouponUsageStatus::Consumed,
            'reserved_at' => null,
            'expires_at' => null,
            'consumed_at' => $createdAt,
            'released_at' => null,
            'release_reason' => null,
            'late_callback_exception' => false,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->save();
    }

    private function assertCouponCapacityLocked(Coupon $coupon, User $customer, CarbonInterface $at): void
    {
        $holding = CouponUsage::query()
            ->where('coupon_id', $coupon->id)
            ->where(function ($query) use ($at): void {
                $query->where('status', CouponUsageStatus::Consumed->value)
                    ->orWhere(function ($reserved) use ($at): void {
                        $reserved->where('status', CouponUsageStatus::Reserved->value)
                            ->where('expires_at', '>', $at->format('Y-m-d H:i:s.u'));
                    });
            });
        $total = (clone $holding)->count();
        $forCustomer = (clone $holding)->where('customer_id', $customer->id)->count();

        if (($coupon->max_uses !== null && $total >= $coupon->max_uses)
            || ($coupon->max_uses_per_user !== null && $forCustomer >= $coupon->max_uses_per_user)) {
            throw ValidationException::withMessages([
                'coupon_code' => 'Mã giảm giá đã hết lượt sử dụng khả dụng.',
            ]);
        }
    }

    /** @param callable(): Order $callback */
    private function runTransaction(callable $callback): Order
    {
        if (DB::getDriverName() !== 'mysql' || DB::connection()->transactionLevel() > 0) {
            return DB::transaction($callback, 3);
        }

        $version = strtolower((string) DB::selectOne('SELECT VERSION() AS version')->version);
        $variable = str_contains($version, 'mariadb') ? 'tx_isolation' : 'transaction_isolation';
        $current = (string) DB::selectOne("SELECT @@SESSION.{$variable} AS isolation_level")->isolation_level;
        $restore = strtoupper(str_replace('-', ' ', $current));

        if (! in_array($restore, ['READ UNCOMMITTED', 'READ COMMITTED', 'REPEATABLE READ', 'SERIALIZABLE'], true)) {
            throw new \RuntimeException('Unsupported transaction isolation level.');
        }

        $changed = $restore !== 'READ COMMITTED';
        if ($changed) {
            DB::unprepared('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        }

        try {
            return DB::transaction($callback, 3);
        } finally {
            if ($changed) {
                DB::unprepared('SET SESSION TRANSACTION ISOLATION LEVEL '.$restore);
            }
        }
    }

    private function findExisting(User $customer, string $requestKey): ?Order
    {
        return Order::query()
            ->where('user_id', $customer->id)
            ->where('request_key', $requestKey)
            ->lockForUpdate()
            ->first();
    }

    private function replay(Order $order, CheckoutRecipient $recipient, ?string $couponCode, string $expectedFingerprint): Order
    {
        $storedCouponCode = $order->coupon_snapshot_json['code'] ?? null;
        if ($order->payment_method !== PaymentMethod::CashOnDelivery
            || ! is_string($order->idempotency_fingerprint)
            || ! hash_equals($order->idempotency_fingerprint, $expectedFingerprint)
            || $recipient->snapshot() !== [
                'recipient_name' => $order->recipient_name,
                'recipient_email' => $order->recipient_email,
                'recipient_phone' => $order->recipient_phone,
                'recipient_address' => $order->recipient_address,
                'recipient_region' => $order->recipient_region,
            ]
            || $storedCouponCode !== $couponCode) {
            throw ValidationException::withMessages([
                'request_key' => 'Khóa chống tạo đơn trùng đã được dùng cho một payload checkout khác.',
            ]);
        }

        return $this->loadReceipt($order);
    }

    private function loadReceipt(Order $order): Order
    {
        return $order->loadMissing(['items', 'statusHistories', 'couponUsage']);
    }

    private function assertActiveCustomer(User $customer): void
    {
        if (UserRole::tryFrom((string) $customer->getRawOriginal('role')) !== UserRole::Customer
            || UserStatus::tryFrom((string) $customer->getRawOriginal('status')) !== UserStatus::Active) {
            throw new AuthorizationException('Only active customers can create COD Orders.');
        }
    }

    /** @return list<int> */
    private function cartProductIds(User $customer): array
    {
        return CartItem::query()
            ->where('user_id', $customer->id)
            ->orderBy('product_id')
            ->pluck('product_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function isRequestKeyDuplicate(QueryException $exception): bool
    {
        $message = mb_strtolower($exception->getMessage());

        return str_contains($message, 'orders_user_request_unique')
            || str_contains($message, 'orders.user_id, orders.request_key');
    }
}
