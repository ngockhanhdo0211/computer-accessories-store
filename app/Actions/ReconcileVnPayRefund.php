<?php

namespace App\Actions;

use App\Actions\Concerns\AuthorizesActiveAdmin;
use App\Enums\RefundGatewayAttemptStatus;
use App\Models\AuditLog;
use App\Models\PaymentAttempt;
use App\Models\Refund;
use App\Models\RefundGatewayAttempt;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReconcileVnPayRefund
{
    use AuthorizesActiveAdmin;

    public function __construct(private readonly CompleteVnPayRefund $completeRefund) {}

    public function handle(
        Refund $refund,
        User $actor,
        mixed $eventKey,
        mixed $outcome,
        mixed $note,
        mixed $gatewayReference = null,
    ): RefundGatewayAttempt {
        $gatewayReference = is_string($gatewayReference) && trim($gatewayReference) === '' ? null : $gatewayReference;
        $validated = Validator::make([
            'event_key' => $eventKey,
            'outcome' => $outcome,
            'note' => is_string($note) ? trim($note) : $note,
            'gateway_reference' => is_string($gatewayReference) ? trim($gatewayReference) : $gatewayReference,
        ], [
            'event_key' => ['required', 'uuid'],
            'outcome' => ['required', Rule::in(['succeeded', 'failed'])],
            'note' => ['required', 'string', 'max:500'],
            'gateway_reference' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9._-]+$/'],
        ])->validate();
        $fingerprint = hash('sha256', json_encode([
            'refund_id' => $refund->id,
            'outcome' => $validated['outcome'],
            'note' => $validated['note'],
            'gateway_reference' => $validated['gateway_reference'] ?? null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($refund, $actor, $validated, $fingerprint): RefundGatewayAttempt {
            $currentActor = User::query()->lockForUpdate()->find($actor->getKey());
            if (! $currentActor instanceof User) {
                throw ValidationException::withMessages(['authorization' => 'Tài khoản không có quyền đối soát Refund.']);
            }
            $this->assertActiveAdmin($currentActor);

            $lockedRefund = Refund::query()->lockForUpdate()->findOrFail($refund->id);
            $paymentAttempt = PaymentAttempt::query()->lockForUpdate()->findOrFail($lockedRefund->payment_attempt_id);
            $gatewayAttempt = RefundGatewayAttempt::query()->where('refund_id', $lockedRefund->id)->lockForUpdate()->firstOrFail();
            $eventOwner = RefundGatewayAttempt::query()->where('reconciliation_event_key', $validated['event_key'])->lockForUpdate()->first();
            if ($eventOwner !== null) {
                if ($eventOwner->id !== $gatewayAttempt->id
                    || $eventOwner->reconciliation_fingerprint !== $fingerprint
                    || $eventOwner->status->value !== $validated['outcome']) {
                    throw ValidationException::withMessages(['event_key' => 'Mã chống lặp đã được dùng cho kết quả đối soát khác.']);
                }

                return $gatewayAttempt;
            }
            if (AuditLog::query()->where('request_id', $validated['event_key'])->lockForUpdate()->first() !== null) {
                throw ValidationException::withMessages(['event_key' => 'Mã chống lặp đã được dùng cho thao tác khác.']);
            }
            if ($gatewayAttempt->status !== RefundGatewayAttemptStatus::Ambiguous) {
                throw ValidationException::withMessages(['refund' => 'Chỉ gateway attempt ambiguous mới được đối soát thủ công.']);
            }
            $newReference = $validated['gateway_reference'] ?? $gatewayAttempt->gateway_reference;
            if ($gatewayAttempt->gateway_reference !== null
                && ($validated['gateway_reference'] ?? $gatewayAttempt->gateway_reference) !== $gatewayAttempt->gateway_reference) {
                throw ValidationException::withMessages(['gateway_reference' => 'Chứng từ nhập vào xung đột với gateway evidence hiện có.']);
            }

            $reconciledAt = CarbonImmutable::now('UTC');
            $target = RefundGatewayAttemptStatus::from($validated['outcome']);
            $gatewayAttempt->transitionLifecycle([
                'status' => $target,
                'gateway_reference' => $newReference,
                'completed_at' => $reconciledAt,
                'reconciled_by' => $currentActor->id,
                'reconciliation_event_key' => $validated['event_key'],
                'reconciliation_fingerprint' => $fingerprint,
                'reconciliation_note' => $validated['note'],
                'reconciled_at' => $reconciledAt,
            ]);
            $this->completeRefund->handleLocked(
                $lockedRefund,
                $gatewayAttempt,
                $paymentAttempt,
                $target === RefundGatewayAttemptStatus::Succeeded,
                $reconciledAt,
            );
            (new AuditLog)->forceFill([
                'actor_id' => $currentActor->id,
                'action' => 'refund.vnpay.reconciled_'.$target->value,
                'subject_type' => Refund::class,
                'subject_id' => $lockedRefund->id,
                'before_json' => ['refund_status' => 'pending', 'gateway_status' => 'ambiguous'],
                'after_json' => [
                    'refund_status' => $lockedRefund->fresh()->status->value,
                    'gateway_status' => $target->value,
                    'note' => $validated['note'],
                    'gateway_reference' => $newReference,
                ],
                'request_id' => $validated['event_key'],
                'created_at' => $reconciledAt,
            ])->save();

            return $gatewayAttempt->fresh();
        }, 3);
    }
}
