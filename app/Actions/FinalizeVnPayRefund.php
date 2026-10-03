<?php

namespace App\Actions;

use App\Enums\RefundGatewayAttemptStatus;
use App\Models\AuditLog;
use App\Models\PaymentAttempt;
use App\Models\Refund;
use App\Models\RefundGatewayAttempt;
use App\ValueObjects\VnPayRefundRequest;
use App\ValueObjects\VnPayRefundResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinalizeVnPayRefund
{
    public function __construct(private readonly CompleteVnPayRefund $completeRefund) {}

    public function handle(
        int $gatewayAttemptId,
        VnPayRefundRequest $request,
        VnPayRefundResult $result,
    ): RefundGatewayAttempt {
        return DB::transaction(function () use ($gatewayAttemptId, $request, $result): RefundGatewayAttempt {
            $identity = RefundGatewayAttempt::query()->findOrFail($gatewayAttemptId);
            $refund = Refund::query()->lockForUpdate()->findOrFail($identity->refund_id);
            $paymentAttempt = PaymentAttempt::query()->lockForUpdate()->findOrFail($refund->payment_attempt_id);
            $gatewayAttempt = RefundGatewayAttempt::query()->lockForUpdate()->findOrFail($gatewayAttemptId);

            if ($gatewayAttempt->request_id !== $request->requestId()
                || $gatewayAttempt->request_fingerprint !== $request->requestFingerprint) {
                throw ValidationException::withMessages(['refund' => 'Gateway response không thuộc request Refund đã lưu.']);
            }
            if (in_array($gatewayAttempt->status, [RefundGatewayAttemptStatus::Succeeded, RefundGatewayAttemptStatus::Failed], true)) {
                return $gatewayAttempt;
            }
            if (! in_array($gatewayAttempt->status, [RefundGatewayAttemptStatus::Submitted, RefundGatewayAttemptStatus::Ambiguous], true)) {
                throw ValidationException::withMessages(['refund' => 'Gateway attempt không còn ở trạng thái có thể ghi response.']);
            }
            if ($gatewayAttempt->status === RefundGatewayAttemptStatus::Ambiguous
                && $result->status === RefundGatewayAttemptStatus::Ambiguous) {
                return $gatewayAttempt;
            }
            if ($gatewayAttempt->amount_vnd !== $refund->amount_vnd) {
                throw ValidationException::withMessages(['refund' => 'Gateway amount không khớp Refund.']);
            }

            $previousStatus = $gatewayAttempt->status;

            $gatewayAttempt->transitionLifecycle([
                'status' => $result->status,
                'response_code' => $result->responseCode,
                'transaction_status' => $result->transactionStatus,
                'gateway_reference' => $result->gatewayReference,
                'response_fingerprint' => $result->responseFingerprint,
                'completed_at' => $result->completedAt,
            ]);

            if ($result->status === RefundGatewayAttemptStatus::Succeeded) {
                $this->completeRefund->handleLocked($refund, $gatewayAttempt, $paymentAttempt, true, $result->completedAt);
            } elseif ($result->status === RefundGatewayAttemptStatus::Failed) {
                $this->completeRefund->handleLocked($refund, $gatewayAttempt, $paymentAttempt, false, $result->completedAt);
            }

            (new AuditLog)->forceFill([
                'actor_id' => $gatewayAttempt->submitted_by,
                'action' => 'refund.vnpay.'.$result->status->value,
                'subject_type' => Refund::class,
                'subject_id' => $refund->id,
                'before_json' => ['refund_status' => 'pending', 'gateway_status' => $previousStatus->value],
                'after_json' => [
                    'refund_status' => $refund->fresh()->status->value,
                    'gateway_status' => $result->status->value,
                    'response_code' => $result->responseCode,
                    'transaction_status' => $result->transactionStatus,
                ],
                'request_id' => $gatewayAttempt->submission_event_key,
                'created_at' => $result->completedAt,
            ])->save();

            return $gatewayAttempt->fresh();
        }, 3);
    }
}
