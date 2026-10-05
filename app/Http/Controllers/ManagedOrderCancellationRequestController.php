<?php

namespace App\Http\Controllers;

use App\Actions\ReviewOrderCancellationRequest as ReviewAction;
use App\Enums\OrderCancellationRequestStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Http\Requests\ReviewOrderCancellationRequest;
use App\Models\OrderCancellationRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ManagedOrderCancellationRequestController extends Controller
{
    public function index(Request $request): View
    {
        return view('managed-cancellation-requests.index', ['requests' => OrderCancellationRequest::query()->with(['order', 'customer'])->latest()->paginate(20), 'routePrefix' => $this->prefix($request)]);
    }

    public function show(Request $request, OrderCancellationRequest $cancellationRequest): View
    {
        $cancellationRequest->load(['order.items', 'order.paymentAttempt', 'order.refund', 'customer', 'reviewer']);
        $order = $cancellationRequest->order;
        $validOrder = $cancellationRequest->status === OrderCancellationRequestStatus::Pending
            && $order->status === OrderStatus::Placed
            && $order->delivered_at === null
            && $order->user_id === $cancellationRequest->customer_id;
        $validPayment = match ($order->payment_method) {
            PaymentMethod::CashOnDelivery => $order->payment_status === PaymentStatus::Unpaid
                && $order->payment_attempt_id === null,
            PaymentMethod::VnPay => $order->payment_status === PaymentStatus::Paid
                && $order->paymentAttempt !== null
                && $order->paymentAttempt->status === PaymentStatus::Paid
                && $order->paymentAttempt->verified_at !== null
                && $order->paymentAttempt->gateway_transaction_id !== null
                && $order->paymentAttempt->user_id === $order->user_id
                && $order->paymentAttempt->amount_vnd === $order->total_vnd
                && $order->refund === null,
        };

        return view('managed-cancellation-requests.show', [
            'cancellationRequest' => $cancellationRequest,
            'routePrefix' => $this->prefix($request),
            'canApprove' => $validOrder && $validPayment,
            'isDrifted' => $cancellationRequest->status === OrderCancellationRequestStatus::Pending && ! $validOrder,
        ]);
    }

    public function approve(ReviewOrderCancellationRequest $request, OrderCancellationRequest $cancellationRequest, ReviewAction $review): RedirectResponse
    {
        return $this->review($request, $cancellationRequest, $review, OrderCancellationRequestStatus::Approved);
    }

    public function reject(ReviewOrderCancellationRequest $request, OrderCancellationRequest $cancellationRequest, ReviewAction $review): RedirectResponse
    {
        if ($request->validated('note') === null) {
            return back()->withErrors(['note' => 'Lý do từ chối là bắt buộc.'], 'rejectCancellation')->withInput();
        }

        return $this->review($request, $cancellationRequest, $review, OrderCancellationRequestStatus::Rejected);
    }

    private function review(ReviewOrderCancellationRequest $request, OrderCancellationRequest $model, ReviewAction $review, OrderCancellationRequestStatus $decision): RedirectResponse
    {
        try {
            $review->handle($model, $request->user(), $request->validated('event_key'), $decision->value, $request->validated('note'));
        } catch (ValidationException $exception) {
            $bag = $decision === OrderCancellationRequestStatus::Rejected ? 'rejectCancellation' : 'approveCancellation';

            return back()->withErrors($exception->errors(), $bag)->withInput();
        }

        return redirect()->route($this->prefix($request).'.order-cancellation-requests.show', $model)->with('status', 'Đã xử lý yêu cầu hủy đơn.');
    }

    private function prefix(Request $request): string
    {
        return $request->routeIs('admin.*') ? 'admin' : 'employee';
    }
}
