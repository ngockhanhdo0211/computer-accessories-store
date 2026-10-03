<?php

namespace App\Actions;

use App\Actions\Concerns\AuthorizesActiveAdmin;
use App\Contracts\VnPayRefundTransport;
use App\Enums\PaymentStatus;
use App\Enums\RefundGatewayAttemptStatus;
use App\Enums\RefundStatus;
use App\Models\AuditLog;
use App\Models\PaymentAttempt;
use App\Models\Refund;
use App\Models\RefundGatewayAttempt;
use App\Models\User;
use App\Services\VnPayRefundGateway;
use App\ValueObjects\VnPayRefundRequest;
use App\ValueObjects\VnPayRefundResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class SubmitVnPayRefund
{
    use AuthorizesActiveAdmin;

    public function __construct(
        private readonly VnPayRefundGateway $requestBuilder,
        private readonly VnPayRefundTransport $transport,
        private readonly FinalizeVnPayRefund $finalize,
    ) {}

    public function handle(Refund $refund, User $actor, mixed $eventKey): RefundGatewayAttempt
    {
        $validated = Validator::make(['event_key' => $eventKey], ['event_key' => ['required', 'uuid']])->validate();
        $prepared = DB::transaction(function () use ($refund, $actor, $validated): array {
            $currentActor = User::query()->lockForUpdate()->find($actor->getKey());
            if (! $currentActor instanceof User) {
                throw ValidationException::withMessages(['authorization' => 'Tài khoản không có quyền xử lý Refund.']);
            }
            $this->assertActiveAdmin($currentActor);

            $lockedRefund = Refund::query()->lockForUpdate()->findOrFail($refund->id);
            $paymentAttempt = PaymentAttempt::query()->lockForUpdate()->findOrFail($lockedRefund->payment_attempt_id);
            $eventOwner = RefundGatewayAttempt::query()
                ->where('submission_event_key', $validated['event_key'])
                ->orWhere('reconciliation_event_key', $validated['event_key'])
                ->lockForUpdate()->first();
            if ($eventOwner !== null && $eventOwner->refund_id !== $lockedRefund->id) {
                throw ValidationException::withMessages(['event_key' => 'Mã chống lặp đã được dùng cho Refund khác.']);
            }
            $existing = RefundGatewayAttempt::query()->where('refund_id', $lockedRefund->id)->lockForUpdate()->first();
            if ($existing !== null) {
                return [$existing, null];
            }
            if ($lockedRefund->status !== RefundStatus::Pending
                || $paymentAttempt->status !== PaymentStatus::Paid
                || $paymentAttempt->verified_at === null
                || $paymentAttempt->gateway_transaction_id === null
                || $lockedRefund->amount_vnd !== $paymentAttempt->amount_vnd) {
                throw ValidationException::withMessages(['refund' => 'Refund chưa đủ điều kiện gửi tới VNPay.']);
            }
            if ($lockedRefund->order_id !== null
                && ! $paymentAttempt->order()->whereKey($lockedRefund->order_id)->exists()) {
                throw ValidationException::withMessages(['refund' => 'Order của Refund không khớp Payment Attempt.']);
            }

            $submittedAt = CarbonImmutable::now('UTC');
            $requestId = $this->newRequestId();
            $request = $this->requestBuilder->buildRequest($lockedRefund, $paymentAttempt, $requestId, $submittedAt);
            $gatewayAttempt = new RefundGatewayAttempt;
            $gatewayAttempt->forceFill([
                'refund_id' => $lockedRefund->id,
                'submitted_by' => $currentActor->id,
                'submission_event_key' => $validated['event_key'],
                'request_id' => $requestId,
                'request_fingerprint' => $request->requestFingerprint,
                'amount_vnd' => $lockedRefund->amount_vnd,
                'submitted_at' => $submittedAt,
                'status' => RefundGatewayAttemptStatus::Submitted,
                'created_at' => $submittedAt,
                'updated_at' => $submittedAt,
            ])->save();
            (new AuditLog)->forceFill([
                'actor_id' => $currentActor->id,
                'action' => 'refund.vnpay.submitted',
                'subject_type' => Refund::class,
                'subject_id' => $lockedRefund->id,
                'before_json' => ['refund_status' => RefundStatus::Pending->value, 'gateway_attempt' => null],
                'after_json' => ['refund_status' => RefundStatus::Pending->value, 'gateway_status' => RefundGatewayAttemptStatus::Submitted->value, 'request_id' => $requestId],
                'request_id' => $validated['event_key'],
                'created_at' => $submittedAt,
            ])->save();

            return [$gatewayAttempt, $request];
        }, 3);

        /** @var RefundGatewayAttempt $gatewayAttempt */
        [$gatewayAttempt, $request] = $prepared;
        if (! $request instanceof VnPayRefundRequest) {
            return $gatewayAttempt;
        }

        try {
            $result = $this->transport->send($request);
        } catch (Throwable) {
            $result = VnPayRefundResult::ambiguous();
        }

        return $this->finalize->handle($gatewayAttempt->id, $request, $result);
    }

    private function newRequestId(): string
    {
        do {
            $requestId = 'RF'.strtoupper(bin2hex(random_bytes(15)));
        } while (RefundGatewayAttempt::query()->where('request_id', $requestId)->exists());

        return $requestId;
    }
}
