<?php

namespace App\Http\Controllers;

use App\Actions\GetOrders;
use App\Actions\TransitionOrderStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Http\Requests\OrderIndexRequest;
use App\Http\Requests\TransitionOrderStatusRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

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

    public function show(Request $request, GetOrders $orders, string $orderCode): View
    {
        return view('managed-orders.show', [
            'order' => $orders->managedDetail($orderCode),
            'routePrefix' => $this->routePrefix($request),
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

    private function routePrefix(Request $request): string
    {
        return $request->routeIs('admin.*') ? 'admin' : 'employee';
    }
}
