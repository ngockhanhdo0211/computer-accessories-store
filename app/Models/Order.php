<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\ValueObjects\OrderSnapshot;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use LogicException;

class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    protected $guarded = ['*'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected static function booted(): void
    {
        static::creating(function (Order $order): void {
            $order->assertSnapshot();
            $order->assertCreationInvariant();
        });

        static::updating(function (Order $order): void {
            $immutable = [
                'user_id', 'payment_attempt_id', 'request_key', 'order_code', 'payment_method',
                'recipient_name', 'recipient_email', 'recipient_phone', 'recipient_address',
                'recipient_region', 'coupon_id', 'coupon_snapshot_json', 'items_subtotal_vnd',
                'item_discount_vnd', 'shipping_fee_vnd', 'shipping_discount_vnd', 'total_vnd',
            ];

            if ($order->isDirty($immutable)) {
                throw new LogicException('Order identity, method, totals and snapshots are immutable.');
            }
        });

        static::deleting(fn () => throw new LogicException('Orders cannot be deleted.'));
    }

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'payment_method' => PaymentMethod::class,
            'payment_status' => PaymentStatus::class,
            'coupon_snapshot_json' => 'array',
            'items_subtotal_vnd' => 'integer',
            'item_discount_vnd' => 'integer',
            'shipping_fee_vnd' => 'integer',
            'shipping_discount_vnd' => 'integer',
            'total_vnd' => 'integer',
            'delivered_at' => 'immutable_datetime',
        ];
    }

    protected function totalDiscountVnd(): Attribute
    {
        return Attribute::get(function (): int {
            if ($this->item_discount_vnd > PHP_INT_MAX - $this->shipping_discount_vnd) {
                throw new LogicException('Order total discount exceeds the integer range.');
            }

            return $this->item_discount_vnd + $this->shipping_discount_vnd;
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function paymentAttempt(): BelongsTo
    {
        return $this->belongsTo(PaymentAttempt::class);
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('id');
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('created_at')->orderBy('id');
    }

    public function assertItemsReconcile(): void
    {
        $items = $this->items()->get();
        $lineSubtotal = 0;
        $lineDiscount = 0;
        $orderLines = [];

        foreach ($items as $item) {
            if ($item->line_subtotal_vnd > PHP_INT_MAX - $lineSubtotal
                || $item->discount_vnd > PHP_INT_MAX - $lineDiscount) {
                throw new LogicException('Order item totals exceed the integer range.');
            }

            $lineSubtotal += $item->line_subtotal_vnd;
            $lineDiscount += $item->discount_vnd;
            $orderLines[] = [
                'product_id' => (int) $item->product_id,
                'product_name' => $item->product_name,
                'sku' => $item->sku,
                'quantity' => $item->quantity,
                'unit_price_vnd' => $item->unit_price_vnd,
                'line_subtotal_vnd' => $item->line_subtotal_vnd,
            ];
        }

        if ($items->isEmpty()
            || $lineSubtotal !== $this->items_subtotal_vnd
            || $lineDiscount !== $this->item_discount_vnd
            || $items->contains(fn (OrderItem $item): bool => $item->line_total_vnd !== $item->line_subtotal_vnd - $item->discount_vnd)) {
            throw new LogicException('Order items do not reconcile with Order totals.');
        }

        if ($this->payment_method === PaymentMethod::VnPay) {
            usort($orderLines, fn (array $left, array $right): int => $left['product_id'] <=> $right['product_id']);

            if ($orderLines !== OrderSnapshot::canonicalAttemptItems($this->paymentAttempt?->items_snapshot_json)) {
                throw new LogicException('VNPay Order items must match the Payment Attempt snapshot.');
            }
        }
    }

    private function assertSnapshot(): void
    {
        new OrderSnapshot(
            (string) $this->recipient_name,
            (string) $this->recipient_email,
            (string) $this->recipient_phone,
            (string) $this->recipient_address,
            (string) $this->recipient_region,
            $this->coupon_snapshot_json,
            (int) $this->items_subtotal_vnd,
            (int) $this->item_discount_vnd,
            (int) $this->shipping_fee_vnd,
            (int) $this->shipping_discount_vnd,
            (int) $this->total_vnd,
        );

        if (($this->coupon_id === null) !== ($this->coupon_snapshot_json === null)
            || ($this->coupon_id !== null && $this->coupon_snapshot_json['coupon_id'] !== (int) $this->coupon_id)) {
            throw new LogicException('Order coupon reference and snapshot must match.');
        }
    }

    private function assertCreationInvariant(): void
    {
        if (trim((string) $this->order_code) === '') {
            throw new LogicException('Order code is required.');
        }

        if ($this->payment_method === PaymentMethod::CashOnDelivery) {
            if ($this->payment_attempt_id !== null
                || ! is_string($this->request_key)
                || ! Str::isUuid($this->request_key)
                || $this->payment_status !== PaymentStatus::Unpaid) {
                throw new LogicException('A new COD Order requires its own request key and unpaid status.');
            }

            return;
        }

        if ($this->payment_method !== PaymentMethod::VnPay
            || $this->request_key !== null
            || $this->payment_status !== PaymentStatus::Paid) {
            throw new LogicException('A new VNPay Order requires a verified paid Payment Attempt.');
        }

        $attempt = PaymentAttempt::query()->find($this->payment_attempt_id);
        $pricing = $attempt?->pricing_snapshot_json;
        $recipient = $attempt?->recipient_snapshot_json;
        $shipping = is_array($pricing) ? ($pricing['shipping'] ?? null) : null;
        $totalDiscount = $this->total_discount_vnd;
        $shippingAfterDiscount = $this->shipping_fee_vnd - $this->shipping_discount_vnd;

        if ($attempt === null
            || $attempt->user_id !== (int) $this->user_id
            || $attempt->status !== PaymentStatus::Paid
            || $attempt->verified_at === null
            || $attempt->amount_vnd !== (int) $this->total_vnd
            || $attempt->shipping_fee_vnd !== (int) $this->shipping_fee_vnd
            || $attempt->coupon_id !== $this->coupon_id
            || ! is_array($pricing)
            || ! is_array($recipient)
            || ! is_array($shipping)
            || ($pricing['cart_subtotal_vnd'] ?? null) !== (int) $this->items_subtotal_vnd
            || ($pricing['item_discount_vnd'] ?? null) !== (int) $this->item_discount_vnd
            || ($pricing['shipping_fee_vnd'] ?? null) !== (int) $this->shipping_fee_vnd
            || ($pricing['shipping_discount_vnd'] ?? null) !== (int) $this->shipping_discount_vnd
            || ($pricing['shipping_fee_after_discount_vnd'] ?? null) !== $shippingAfterDiscount
            || ($pricing['total_discount_vnd'] ?? null) !== $totalDiscount
            || ($pricing['total_vnd'] ?? null) !== (int) $this->total_vnd
            || ($recipient['recipient_name'] ?? null) !== $this->recipient_name
            || ($recipient['recipient_email'] ?? null) !== $this->recipient_email
            || ($recipient['recipient_phone'] ?? null) !== $this->recipient_phone
            || ($recipient['recipient_address'] ?? null) !== $this->recipient_address
            || ($recipient['recipient_region'] ?? null) !== $this->recipient_region) {
            throw new LogicException('VNPay Order snapshots must match the verified Payment Attempt.');
        }

        OrderSnapshot::assertShippingSnapshot(
            $shipping,
            (int) $attempt->shipping_rate_id,
            (int) $this->shipping_fee_vnd,
        );
    }
}
