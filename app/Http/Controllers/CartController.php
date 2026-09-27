<?php

namespace App\Http\Controllers;

use App\Actions\AddCartItem;
use App\Actions\GetCartSummary;
use App\Actions\RemoveCartItem;
use App\Actions\UpdateCartItem;
use App\Http\Requests\AddCartItemRequest;
use App\Http\Requests\UpdateCartItemRequest;
use App\Models\CartItem;
use App\Models\Product;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CartController extends Controller
{
    public function index(Request $request, GetCartSummary $summary): View
    {
        $data = $summary->handle($request->user());
        $request->attributes->set('cartItemCount', $data['line_count']);

        return view('cart.index', $data);
    }

    public function store(
        AddCartItemRequest $request,
        Product $product,
        AddCartItem $action,
    ): RedirectResponse {
        $action->handle($request->user(), $product, (int) $request->validated('quantity'));

        return redirect()->route('cart.index')->with('status', 'Đã thêm sản phẩm vào giỏ hàng.');
    }

    public function update(
        UpdateCartItemRequest $request,
        CartItem $cartItem,
        UpdateCartItem $action,
    ): RedirectResponse {
        try {
            $action->handle($request->user(), $cartItem, (int) $request->validated('quantity'));
        } catch (ValidationException $exception) {
            return redirect()->route('cart.index')
                ->withErrors($exception->errors())
                ->withInput([
                    'quantity' => $request->validated('quantity'),
                    'cart_item_id' => $cartItem->id,
                ]);
        }

        return redirect()->route('cart.index')->with('status', 'Đã cập nhật số lượng.');
    }

    public function destroy(
        Request $request,
        CartItem $cartItem,
        RemoveCartItem $action,
    ): RedirectResponse {
        $action->handle($request->user(), $cartItem);

        return redirect()->route('cart.index')->with('status', 'Đã xóa sản phẩm khỏi giỏ hàng.');
    }
}
