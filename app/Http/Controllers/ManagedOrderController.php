<?php

namespace App\Http\Controllers;

use App\Actions\CancelCodOrder;
use App\Actions\DeliverCodOrder;
use App\Actions\GetOrders;
use App\Actions\GetReturnInspectionOperations;
use App\Actions\OrderHasCompletedReturnInspections;
use App\Actions\TransitionOrderStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Http\Requests\CancelCodOrderRequest;
use App\Http\Requests\DeliverCodOrderRequest;
use App\Http\Requests\OrderIndexRequest;
use App\Http\Requests\TransitionOrderStatusRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ManagedOrderController extends Controller
{
    public function index(OrderIndexRequest $request, GetOrders $orders): View|RedirectResponse
    {
        $filters = $request->validated();
        $result = $orders->forManagement($filters);
        $routePrefix = $this->routePrefix($request);

        if ($result->isEmpty() && $result->total() > 0) {
            return redirect()->route($routePrefix.'.orders.index', [
                ...$request->safe()->except('page'),
                'page' => $result->lastPage(),
            ]);
        }

        return view('managed-orders.index', [
            'orders' => $result,
            'filters' => $filters,
            'routePrefix' => $routePrefix,
            'orderStatuses' => OrderStatus::cases(),
            'paymentStatuses' => PaymentStatus::cases(),
            'paymentMethods' => PaymentMethod::cases(),
        ]);
    }

    public function show(
        Request $request,
        GetOrders $orders,
        OrderHasCompletedReturnInspections $inspectionReadiness,
        GetReturnInspectionOperations $inspectionOperations,
        string $orderCode,
    ): View {
        $order = $orders->managedDetail($orderCode);
        $role = UserRole::tryFrom((string) $request->user()->getRawOriginal('role'));
        $inspectionReady = $inspectionReadiness->handle($order);
        $order->items->load('returnInspection');

        return view('managed-orders.show', [
            'order' => $order,
            'routePrefix' => $this->routePrefix($request),
            'inspectionReady' => $inspectionReady,
            'inspectionCompletedCount' => $order->items->filter(fn ($item) => $item->returnInspection?->isCompleted())->count(),
            'canManageInspections' => $inspectionOperations->allows($request->user(), $order),
            'canCancelCod' => $order->payment_method === PaymentMethod::CashOnDelivery
                && $order->payment_status === PaymentStatus::Unpaid
                && $order->delivered_at === null
                && in_array($order->status, [OrderStatus::Placed, OrderStatus::AwaitingHandoff, OrderStatus::InTransit], true)
                && ($order->status !== OrderStatus::InTransit || $role === UserRole::Admin),
            'canDeliverCod' => $order->payment_method === PaymentMethod::CashOnDelivery
                && $order->payment_status === PaymentStatus::Unpaid
                && $order->delivered_at === null
                && $order->status === OrderStatus::InTransit,
        ]);
    }

    public function transition(
        TransitionOrderStatusRequest $request,
        TransitionOrderStatus $transition,
        string $orderCode,
    ): RedirectResponse {
        $transition->handle(
            $orderCode,
            $request->user(),
            $request->targetStatus(),
            $request->eventKey(),
            $request->validated('reason'),
        );

        return redirect()
            ->route($this->routePrefix($request).'.orders.show', $orderCode)
            ->with('status', 'Đã cập nhật tiến trình vận chuyển.');
    }

    public function cancel(
        CancelCodOrderRequest $request,
        CancelCodOrder $cancel,
        string $orderCode,
    ): RedirectResponse {
        try {
            $cancel->handle(
                $orderCode,
                $request->user(),
                $request->validated('event_key'),
                $request->validated('reason'),
            );
        } catch (ValidationException $exception) {
            return redirect()
                ->route($this->routePrefix($request).'.orders.show', $orderCode)
                ->withErrors($exception->errors(), 'cancelOrder')
                ->withInput($request->safe()->only(['event_key', 'reason']));
        }

        return redirect()
            ->route($this->routePrefix($request).'.orders.show', $orderCode)
            ->with('status', 'Đã hủy đơn COD và hoàn kho theo kết quả kiểm tra.');
    }

    public function deliver(
        DeliverCodOrderRequest $request,
        DeliverCodOrder $deliver,
        string $orderCode,
    ): RedirectResponse {
        try {
            $deliver->handle(
                $orderCode,
                $request->user(),
                $request->validated('event_key'),
                $request->validated('reason'),
            );
        } catch (ValidationException $exception) {
            return redirect()
                ->route($this->routePrefix($request).'.orders.show', $orderCode)
                ->withErrors($exception->errors(), 'deliverOrder')
                ->withInput($request->safe()->only(['event_key', 'reason']));
        }

        return redirect()
            ->route($this->routePrefix($request).'.orders.show', $orderCode)
            ->with('status', 'Đã xác nhận giao đơn COD thành công.');
    }

    private function routePrefix(Request $request): string
    {
        return $request->routeIs('admin.*') ? 'admin' : 'employee';
    }
}
