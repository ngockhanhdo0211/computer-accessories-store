<?php

namespace App\Http\Controllers\Admin;

use App\Actions\MarkSubmittedRefundAmbiguous;
use App\Actions\ReconcileVnPayRefund;
use App\Actions\SubmitVnPayRefund;
use App\Enums\RefundGatewayAttemptStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\MarkRefundAmbiguousRequest;
use App\Http\Requests\ReconcileRefundRequest;
use App\Http\Requests\SubmitRefundRequest;
use App\Models\Refund;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class RefundController extends Controller
{
    public function index(): View
    {
        return view('admin.refunds.index', [
            'refunds' => Refund::query()
                ->with(['paymentAttempt', 'order', 'gatewayAttempt'])
                ->latest('created_at')->latest('id')->paginate(20),
        ]);
    }

    public function show(Refund $refund): View
    {
        $staleSeconds = config('services.vnpay.refund_submission_stale_seconds');
        $requestTimeout = config('services.vnpay.refund_timeout');
        $hasValidStaleLease = is_int($staleSeconds) && is_int($requestTimeout)
            && $staleSeconds >= 60 && $staleSeconds <= 3600 && $staleSeconds > $requestTimeout;

        return view('admin.refunds.show', [
            'refund' => $refund->load(['paymentAttempt.user', 'order', 'gatewayAttempt.submitter', 'gatewayAttempt.reconciliationActor']),
            'submissionStaleSeconds' => $hasValidStaleLease ? $staleSeconds : null,
        ]);
    }

    public function submit(
        SubmitRefundRequest $request,
        Refund $refund,
        SubmitVnPayRefund $submit,
    ): RedirectResponse {
        try {
            $gatewayAttempt = $submit->handle($refund, $request->user(), $request->validated('event_key'));
        } catch (ValidationException $exception) {
            return redirect()->route('admin.refunds.show', $refund)
                ->withErrors($exception->errors(), 'submitRefund')
                ->withInput($request->safe()->only('event_key'));
        }

        $message = match ($gatewayAttempt->status) {
            RefundGatewayAttemptStatus::Succeeded => 'VNPay xác nhận hoàn tiền thành công.',
            RefundGatewayAttemptStatus::Failed => 'VNPay từ chối yêu cầu hoàn tiền.',
            RefundGatewayAttemptStatus::Ambiguous => 'Kết quả không rõ. Không gửi lại; hãy đối soát trên VNPay Merchant Portal.',
            RefundGatewayAttemptStatus::Submitted => 'Yêu cầu đã được ghi nhận. Không gửi lại; hãy kiểm tra evidence nếu tiến trình bị gián đoạn.',
        };

        return redirect()->route('admin.refunds.show', $refund)->with('status', $message);
    }

    public function reconcile(
        ReconcileRefundRequest $request,
        Refund $refund,
        ReconcileVnPayRefund $reconcile,
    ): RedirectResponse {
        try {
            $reconcile->handle(
                $refund,
                $request->user(),
                $request->validated('event_key'),
                $request->validated('outcome'),
                $request->validated('note'),
                $request->validated('gateway_reference'),
            );
        } catch (ValidationException $exception) {
            return redirect()->route('admin.refunds.show', $refund)
                ->withErrors($exception->errors(), 'reconcileRefund')
                ->withInput($request->safe()->only(['event_key', 'outcome', 'note', 'gateway_reference']));
        }

        return redirect()->route('admin.refunds.show', $refund)->with('status', 'Đã lưu kết quả đối soát thủ công.');
    }

    public function markAmbiguous(
        MarkRefundAmbiguousRequest $request,
        Refund $refund,
        MarkSubmittedRefundAmbiguous $markAmbiguous,
    ): RedirectResponse {
        try {
            $markAmbiguous->handle(
                $refund,
                $request->user(),
                $request->validated('event_key'),
                $request->validated('note'),
            );
        } catch (ValidationException $exception) {
            return redirect()->route('admin.refunds.show', $refund)
                ->withErrors($exception->errors(), 'markRefundAmbiguous')
                ->withInput($request->safe()->only(['event_key', 'note']));
        }

        return redirect()->route('admin.refunds.show', $refund)
            ->with('status', 'Đã đánh dấu request bị gián đoạn là ambiguous. Hãy đối soát trước khi kết luận.');
    }
}
