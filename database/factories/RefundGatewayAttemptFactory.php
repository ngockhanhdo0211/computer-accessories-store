<?php

namespace Database\Factories;

use App\Enums\RefundGatewayAttemptStatus;
use App\Models\Refund;
use App\Models\RefundGatewayAttempt;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<RefundGatewayAttempt> */
class RefundGatewayAttemptFactory extends Factory
{
    protected $model = RefundGatewayAttempt::class;

    public function definition(): array
    {
        $requestId = 'RF'.strtoupper(bin2hex(random_bytes(15)));

        return [
            'refund_id' => Refund::factory(),
            'submitted_by' => User::factory()->admin(),
            'submission_event_key' => (string) Str::uuid(),
            'request_id' => $requestId,
            'request_fingerprint' => hash('sha256', $requestId),
            'amount_vnd' => fn (array $attributes) => Refund::query()->findOrFail($attributes['refund_id'])->amount_vnd,
            'submitted_at' => now(),
            'response_code' => null,
            'transaction_status' => null,
            'gateway_reference' => null,
            'response_fingerprint' => null,
            'completed_at' => null,
            'status' => RefundGatewayAttemptStatus::Submitted,
            'reconciled_by' => null,
            'reconciliation_event_key' => null,
            'reconciliation_fingerprint' => null,
            'reconciliation_note' => null,
            'reconciled_at' => null,
        ];
    }
}
