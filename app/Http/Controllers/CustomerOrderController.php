<?php

namespace App\Http\Controllers;

use App\Actions\GetOrders;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Http\Requests\OrderIndexRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CustomerOrderController extends Controller
{
    public function index(OrderIndexRequest $request, GetOrders $orders): View|RedirectResponse
    {
        $filters = $request->validated();
        $result = $orders->forCustomer($request->user(), $filters);

        if ($result->isEmpty() && $result->total() > 0) {
            return redirect()->route('orders.index', [
                ...$request->safe()->except('page'),
                'page' => $result->lastPage(),
            ]);
        }

        return view('orders.index', [
            'orders' => $result,
            'filters' => $filters,
            'orderStatuses' => OrderStatus::cases(),
            'paymentStatuses' => PaymentStatus::cases(),
            'paymentMethods' => PaymentMethod::cases(),
        ]);
    }

    public function show(Request $request, GetOrders $orders, string $orderCode): View
    {
        return view('orders.show', [
            'order' => $orders->customerDetail($request->user(), $orderCode),
        ]);
    }
}
