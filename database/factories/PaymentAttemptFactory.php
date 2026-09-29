<?php

namespace Database\Factories;

use App\Enums\PaymentStatus;
use App\Models\PaymentAttempt;
use App\Models\ShippingRate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<PaymentAttempt> */
class PaymentAttemptFactory extends Factory
{
    protected $model = PaymentAttempt::class;

    public function definition(): array
    {
        $amount = fake()->numberBetween(100_000, 5_000_000);
        $shippingFee = 30_000;

        return [
            'user_id' => User::factory(),
            'shipping_rate_id' => fn () => ShippingRate::query()->value('id')
                ?? throw new \RuntimeException('Shipping rates must exist before creating a Payment Attempt fixture.'),
            'request_key' => (string) Str::uuid(),
            'gateway_reference' => 'PA-'.Str::uuid(),
            'gateway_transaction_id' => null,
            'status' => PaymentStatus::Unpaid,
            'amount_vnd' => $amount,
            'items_snapshot_json' => [[
                'product_id' => 1,
                'category_id' => 1,
                'brand_id' => 1,
                'sku' => 'SNAPSHOT-SKU',
                'product_name' => 'Snapshot product',
                'quantity' => 1,
                'unit_price_vnd' => $amount - $shippingFee,
                'line_subtotal_vnd' => $amount - $shippingFee,
            ]],
            'recipient_snapshot_json' => [
                'recipient_name' => 'Nguyen Minh Anh',
                'recipient_email' => 'receiver@example.test',
                'recipient_phone' => '0912345678',
                'recipient_address' => '12 Tran Thai Tong, Dich Vong, Cau Giay, Ha Noi',
                'recipient_region' => 'Ha Noi',
            ],
            'pricing_snapshot_json' => [
                'cart_subtotal_vnd' => $amount - $shippingFee,
                'item_discount_vnd' => 0,
                'shipping_fee_vnd' => $shippingFee,
                'shipping_discount_vnd' => 0,
                'total_discount_vnd' => 0,
                'total_vnd' => $amount,
            ],
            'shipping_fee_vnd' => $shippingFee,
            'coupon_id' => null,
            'expires_at' => now()->addMinutes(15),
            'verified_at' => null,
            'gateway_result_code' => null,
            'late_callback_exception' => false,
        ];
    }
}
