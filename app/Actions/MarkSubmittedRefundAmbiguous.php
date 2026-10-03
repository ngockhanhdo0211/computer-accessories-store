<?php

namespace App\Actions;

use App\Actions\Concerns\AuthorizesActiveAdmin;
use App\Enums\RefundGatewayAttemptStatus;
use App\Enums\RefundStatus;
use App\Models\AuditLog;
use App\Models\PaymentAttempt;
use App\Models\Refund;
use App\Models\RefundGatewayAttempt;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use LogicException;

class MarkSubmittedRefundAmbiguous
{
    use AuthorizesActiveAdmin;

    public function handle(Refund $refund, User $actor, mixed $eventKey, mixed $note): RefundGatewayAttempt
    {
        $validated = Validator::make([
            'event_key' => $eventKey,
            'note' => is_string($note) ? trim($note) : $note,
        ], ['event_key' => ['required', 'uuid'], 'note' => ['required', 'string', 'max:500']])->validate();

        return DB::transaction(function () use ($refund, $actor, $validated): RefundGatewayAttempt {
            $currentActor = User::query()->lockForUpdate()->find($actor->getKey());
            if (! $currentActor instanceof User) {
                throw ValidationException::withMessages(['authorization' => 'Tài khoản không có quyền xử lý Refund.']);
            }
            $this->assertActiveAdmin($currentActor);
            $lockedRefund = Refund::query()->lockForUpdate()->findOrFail($refund->id);
            PaymentAttempt::query()->lockForUpdate()->findOrFail($lockedRefund->payment_attempt_id);
            $gatewayAttempt = RefundGatewayAttempt::query()->where('refund_id', $lockedRefund->id)->lockForUpdate()->firstOrFail();

            $existingAudit = AuditLog::query()->where('request_id', $validated['event_key'])
                ->where('action', 'refund.vnpay.submission_interrupted')->lockForUpdate()->first();
            if ($existingAudit !== null) {
                if ($existingAudit->subject_id !== $lockedRefund->id
                    || ($existingAudit->after_json['note'] ?? null) !== $validated['note']
                    || $gatewayAttempt->status !== RefundGatewayAttemptStatus::Ambiguous) {
                    throw ValidationException::withMessages(['event_key' => 'Mã chống lặp đã được dùng cho thao tác khác.']);
                }

                return $gatewayAttempt;
            }
            if (AuditLog::query()->where('request_id', $validated['event_key'])->lockForUpdate()->first() !== null) {
                throw ValidationException::withMessages(['event_key' => 'Mã chống lặp đã được dùng cho thao tác khác.']);
            }
            if ($lockedRefund->status !== RefundStatus::Pending
                || $gatewayAttempt->status !== RefundGatewayAttemptStatus::Submitted) {
                throw ValidationException::withMessages(['refund' => 'Chỉ request submitted bị gián đoạn mới được đánh dấu ambiguous.']);
            }

            $at = CarbonImmutable::now('UTC');
            $staleSeconds = config('services.vnpay.refund_submission_stale_seconds');
            $requestTimeout = config('services.vnpay.refund_timeout');
            if (! is_int($staleSeconds) || ! is_int($requestTimeout)
                || $staleSeconds < 60 || $staleSeconds > 3600 || $staleSeconds <= $requestTimeout) {
                throw new LogicException('VNPay Refund submission stale lease configuration is invalid.');
            }
            if ($gatewayAttempt->submitted_at->addSeconds($staleSeconds)->isAfter($at)) {
                throw ValidationException::withMessages([
                    'refund' => "Request chỉ được đánh dấu gián đoạn sau {$staleSeconds} giây kể từ server timestamp.",
                ]);
            }
            $gatewayAttempt->transitionLifecycle(['status' => RefundGatewayAttemptStatus::Ambiguous, 'completed_at' => $at]);
            (new AuditLog)->forceFill([
                'actor_id' => $currentActor->id,
                'action' => 'refund.vnpay.submission_interrupted',
                'subject_type' => Refund::class,
                'subject_id' => $lockedRefund->id,
                'before_json' => ['gateway_status' => 'submitted'],
                'after_json' => ['gateway_status' => 'ambiguous', 'note' => $validated['note']],
                'request_id' => $validated['event_key'],
                'created_at' => $at,
            ])->save();

            return $gatewayAttempt->fresh();
        }, 3);
    }
}
