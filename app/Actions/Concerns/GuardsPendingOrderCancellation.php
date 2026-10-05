<?php

namespace App\Actions\Concerns;

use App\Enums\OrderCancellationRequestStatus;
use App\Models\Order;
use App\Models\OrderCancellationRequest;
use Illuminate\Validation\ValidationException;

trait GuardsPendingOrderCancellation
{
    private function assertNoPendingCancellationRequest(Order $order): void
    {
        $pending = OrderCancellationRequest::query()
            ->where('order_id', $order->id)
            ->where('status', OrderCancellationRequestStatus::Pending)
            ->lockForUpdate()
            ->first(['id']);

        if ($pending !== null) {
            throw ValidationException::withMessages([
                'cancellation_request' => 'Yêu cầu hủy đang chờ xử lý. Hãy chấp thuận hoặc từ chối yêu cầu trước khi tiếp tục xử lý đơn.',
            ]);
        }
    }
}
