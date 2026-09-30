<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class OrderReceiptController extends Controller
{
    public function __invoke(Request $request, string $orderCode): View
    {
        $order = Order::query()
            ->where('user_id', $request->user()->id)
            ->where('order_code', $orderCode)
            ->with(['items', 'statusHistories'])
            ->firstOrFail();

        $request->attributes->set('cartItemCount', $request->user()->cartItems()->count());

        return view('orders.show', compact('order'));
    }
}
