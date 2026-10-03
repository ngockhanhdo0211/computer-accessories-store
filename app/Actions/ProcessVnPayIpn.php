<?php

namespace App\Actions;

use App\Exceptions\VnPayCallbackConflict;
use App\Exceptions\VnPayIpnException;
use App\Models\AuditLog;
use App\Models\PaymentAttempt;
use App\Services\VnPayCallbackParser;
use App\ValueObjects\VnPayCallback;
use App\ValueObjects\VnPayIpnResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessVnPayIpn
{
    public function __construct(
        private readonly VnPayCallbackParser $parser,
        private readonly FinalizeVnPayCallback $finalizer,
    ) {}

    public function handle(string $rawQuery): VnPayIpnResponse
    {
        try {
            $callback = $this->parser->parse($rawQuery);
        } catch (VnPayIpnException $exception) {
            return $this->response($exception->responseCode);
        } catch (Throwable) {
            return $this->response('99');
        }

        $attempt = PaymentAttempt::query()
            ->where('gateway_reference', $callback->reference)
            ->first(['id', 'amount_vnd', 'callback_fingerprint']);
        if ($attempt === null) {
            return $this->response('01');
        }
        if ($attempt->amount_vnd !== $callback->amountVnd) {
            return $this->response('04');
        }

        try {
            return $this->response($this->finalizer->handle($callback) ? '02' : '00');
        } catch (VnPayCallbackConflict $exception) {
            $this->recordConflict($callback, $exception);

            return $this->response('99');
        } catch (Throwable) {
            $this->recordTechnicalFailure($callback, (int) $attempt->id);

            return $this->response('99');
        }
    }

    private function recordConflict(VnPayCallback $callback, VnPayCallbackConflict $exception): void
    {
        try {
            DB::transaction(function () use ($callback, $exception): void {
                $attempt = $exception->paymentAttemptId === null
                    ? PaymentAttempt::query()->where('gateway_reference', $callback->reference)->lockForUpdate()->first()
                    : PaymentAttempt::query()->whereKey($exception->paymentAttemptId)->lockForUpdate()->first();
                if ($attempt === null) {
                    return;
                }
                $this->audit($attempt, 'vnpay_callback_conflict', [
                    'reason_code' => $exception->reasonCode,
                    'old_fingerprint' => $exception->oldFingerprint,
                    'new_fingerprint' => $exception->newFingerprint ?? $callback->fingerprint(),
                ]);
            }, 3);
        } catch (Throwable) {
            // Response remains retryable 99; never expose or log raw callback data.
        }
    }

    private function recordTechnicalFailure(VnPayCallback $callback, int $attemptId): void
    {
        try {
            DB::transaction(function () use ($callback, $attemptId): void {
                $attempt = PaymentAttempt::query()->whereKey($attemptId)->lockForUpdate()->first();
                if ($attempt === null) {
                    return;
                }
                $this->audit($attempt, 'vnpay_callback_reconciliation_required', [
                    'reason_code' => 'technical_failure',
                    'new_fingerprint' => $callback->fingerprint(),
                    'old_fingerprint' => $attempt->callback_fingerprint,
                ]);
            }, 3);
        } catch (Throwable) {
            // Best-effort audit only; the callback response must remain 99.
        }
    }

    private function audit(PaymentAttempt $attempt, string $action, array $after): void
    {
        $audit = new AuditLog;
        $audit->forceFill([
            'actor_id' => null, 'action' => $action, 'subject_type' => PaymentAttempt::class,
            'subject_id' => $attempt->id, 'before_json' => null, 'after_json' => $after,
            'request_id' => null, 'created_at' => now('UTC'),
        ])->save();
    }

    private function response(string $code): VnPayIpnResponse
    {
        return new VnPayIpnResponse($code, match ($code) {
            '00' => 'Confirm Success',
            '01' => 'Order not found',
            '02' => 'Order already confirmed',
            '04' => 'Invalid amount',
            '97' => 'Invalid signature',
            default => 'Unknown error',
        });
    }
}
