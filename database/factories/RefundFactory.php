<?php

namespace Database\Factories;

use App\Enums\PaymentStatus;
use App\Enums\RefundReason;
use App\Enums\RefundStatus;
use App\Models\PaymentAttempt;
use App\Models\Refund;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Refund> */
class RefundFactory extends Factory
{
    protected $model = Refund::class;

    public function definition(): array
    {
        $attempt = PaymentAttempt::factory()->make();
        $attempt->save();
        $attempt->finalizeCallback([
            'status' => PaymentStatus::Paid,
            'gateway_transaction_id' => 'TX-'.str_replace('-', '', fake()->uuid()),
            'gateway_result_code' => '00',
            'gateway_transaction_status' => '00',
            'gateway_paid_at' => now(),
            'callback_fingerprint' => hash('sha256', fake()->uuid()),
            'verified_at' => now(),
        ]);

        return [
            'payment_attempt_id' => $attempt->id,
            'order_id' => null,
            'amount_vnd' => $attempt->amount_vnd,
            'reason' => RefundReason::StockUnavailable,
            'status' => RefundStatus::Pending,
            'gateway_refund_reference' => null,
            'note' => null,
        ];
    }
}
