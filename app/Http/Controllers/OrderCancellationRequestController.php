<?php

namespace App\Http\Controllers;

use App\Actions\SubmitOrderCancellationRequest;
use App\Http\Requests\StoreOrderCancellationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class OrderCancellationRequestController extends Controller
{
    public function store(StoreOrderCancellationRequest $request, SubmitOrderCancellationRequest $submit, string $orderCode): RedirectResponse
    {
        try {
            $submit->handle($request->user(), $orderCode, $request->validated('request_key'), $request->validated('reason'));
        } catch (ValidationException $exception) {
            return redirect()->route('orders.show', $orderCode)->withErrors($exception->errors(), 'cancellationRequest')->withInput($request->safe()->only(['request_key', 'reason']));
        }

        return redirect()->route('orders.show', $orderCode)->with('status', 'Đã gửi yêu cầu hủy đơn.');
    }
}
