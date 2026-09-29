<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Order> */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        $subtotal = 200_000;
        $shipping = 30_000;

        return [
            'user_id' => User::factory(),
            'payment_attempt_id' => null,
            'request_key' => (string) Str::uuid(),
            'order_code' => 'ORD-'.strtoupper(Str::random(24)),
            'status' => OrderStatus::Placed,
            'payment_method' => PaymentMethod::CashOnDelivery,
            'payment_status' => PaymentStatus::Unpaid,
            'recipient_name' => 'Nguyen Minh Anh',
            'recipient_email' => 'receiver@example.test',
            'recipient_phone' => '0912345678',
            'recipient_address' => '12 Tran Thai Tong, Dich Vong, Cau Giay, Ha Noi',
            'recipient_region' => 'Ha Noi',
            'coupon_id' => null,
            'coupon_snapshot_json' => null,
            'items_subtotal_vnd' => $subtotal,
            'item_discount_vnd' => 0,
            'shipping_fee_vnd' => $shipping,
            'shipping_discount_vnd' => 0,
            'total_vnd' => $subtotal + $shipping,
            'delivered_at' => null,
        ];
    }

    public function forVerifiedAttempt(PaymentAttempt $attempt): static
    {
        return $this->state(fn () => [
            'user_id' => $attempt->user_id,
            'payment_attempt_id' => $attempt->id,
            'request_key' => null,
            'payment_method' => PaymentMethod::VnPay,
            'payment_status' => PaymentStatus::Paid,
            'recipient_name' => $attempt->recipient_snapshot_json['recipient_name'],
            'recipient_email' => $attempt->recipient_snapshot_json['recipient_email'],
            'recipient_phone' => $attempt->recipient_snapshot_json['recipient_phone'],
            'recipient_address' => $attempt->recipient_snapshot_json['recipient_address'],
            'recipient_region' => $attempt->recipient_snapshot_json['recipient_region'],
            'coupon_id' => $attempt->coupon_id,
            'coupon_snapshot_json' => null,
            'items_subtotal_vnd' => $attempt->pricing_snapshot_json['cart_subtotal_vnd'],
            'item_discount_vnd' => $attempt->pricing_snapshot_json['item_discount_vnd'],
            'shipping_fee_vnd' => $attempt->pricing_snapshot_json['shipping_fee_vnd'],
            'shipping_discount_vnd' => $attempt->pricing_snapshot_json['shipping_discount_vnd'],
            'total_vnd' => $attempt->pricing_snapshot_json['total_vnd'],
        ]);
    }
}
