<?php

namespace Database\Factories;

use App\Enums\CouponUsageStatus;
use App\Enums\PaymentStatus;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CouponUsage> */
class CouponUsageFactory extends Factory
{
    protected $model = CouponUsage::class;

    public function configure(): static
    {
        return $this->afterMaking(function (CouponUsage $usage): void {
            if ($usage->payment_attempt_id === null) {
                $coupon = Coupon::query()->find($usage->coupon_id);
                $customer = User::query()->find($usage->customer_id);

                if ($coupon === null || $customer === null) {
                    return;
                }

                $attempt = PaymentAttempt::factory()->make([
                    'user_id' => $customer->id,
                    'coupon_id' => $coupon->id,
                    'expires_at' => $usage->expires_at,
                ]);
                $pricing = $attempt->pricing_snapshot_json;
                $pricing['coupon'] = [
                    'coupon_id' => $coupon->id,
                    'code' => $coupon->code,
                    'type' => $coupon->type->value,
                    'scope' => $coupon->scope->value,
                    'value' => $coupon->value,
                    'eligible_subtotal_vnd' => $pricing['cart_subtotal_vnd'],
                ];
                $attempt->forceFill(['pricing_snapshot_json' => $pricing])->save();
                $usage->payment_attempt_id = $attempt->id;
            }

            if ($usage->status === CouponUsageStatus::Consumed && $usage->order_id === null) {
                $attempt = PaymentAttempt::query()->findOrFail($usage->payment_attempt_id);
                $attempt->forceFill(['status' => PaymentStatus::Paid, 'verified_at' => now()])->save();
                $usage->order_id = Order::factory()->forVerifiedAttempt($attempt)->create([
                    'coupon_snapshot_json' => $attempt->pricing_snapshot_json['coupon'],
                ])->id;
            }
        });
    }

    public function definition(): array
    {
        $reservedAt = now();
        $expiresAt = $reservedAt->copy()->addMinutes(15);

        return [
            'coupon_id' => Coupon::factory(), 'customer_id' => User::factory(),
            'payment_attempt_id' => null, 'order_id' => null,
            'status' => CouponUsageStatus::Reserved, 'reserved_at' => $reservedAt,
            'expires_at' => $expiresAt, 'consumed_at' => null,
            'released_at' => null, 'release_reason' => null, 'late_callback_exception' => false,
        ];
    }

    public function released(): static
    {
        return $this->state(fn () => [
            'order_id' => null, 'status' => CouponUsageStatus::Released, 'consumed_at' => null,
            'released_at' => now()->addMinute(), 'release_reason' => 'expired', 'late_callback_exception' => false,
        ]);
    }

    public function consumed(?Order $order = null): static
    {
        $state = [
            'order_id' => null, 'status' => CouponUsageStatus::Consumed,
            'consumed_at' => now()->addMinute(), 'released_at' => null, 'release_reason' => null,
            'late_callback_exception' => false,
        ];

        if ($order !== null) {
            $state = array_merge($state, [
                'coupon_id' => $order->coupon_id,
                'customer_id' => $order->user_id,
                'payment_attempt_id' => $order->payment_attempt_id,
                'order_id' => $order->id,
            ]);
        }

        return $this->state($state);
    }

    public function lateConsumed(?Order $order = null): static
    {
        return $this->consumed($order)->state(fn () => [
            'consumed_at' => now()->addMinutes(2), 'released_at' => now()->addMinute(), 'release_reason' => 'expired',
            'late_callback_exception' => true,
        ]);
    }
}
