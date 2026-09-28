<?php

namespace App\Http\Controllers;

use App\Actions\BuildCheckoutQuote;
use App\Actions\GetCartSummary;
use App\Http\Requests\CheckoutQuoteRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CheckoutController extends Controller
{
    public function show(Request $request, GetCartSummary $summary): View|RedirectResponse
    {
        $cart = $summary->handle($request->user());
        $request->attributes->set('cartItemCount', $cart['line_count']);

        if ($cart['line_count'] === 0) {
            return redirect()->route('cart.index')->withErrors([
                'cart' => 'Giỏ hàng đang trống. Hãy thêm sản phẩm trước khi tạo báo giá.',
            ]);
        }

        return view('checkout.show', [
            'cart' => $cart,
            'quote' => null,
            'form' => [
                'recipient_name' => $request->user()->name,
                'recipient_email' => $request->user()->email,
                'recipient_phone' => $request->user()->phone,
                'province' => '',
                'district' => '',
                'ward' => '',
                'address_line' => '',
                'coupon_code' => '',
            ],
        ]);
    }

    public function quote(
        CheckoutQuoteRequest $request,
        BuildCheckoutQuote $action,
    ): View|RedirectResponse {
        try {
            $quote = $action->handle(
                $request->user(),
                $request->recipient(),
                $request->couponCode(),
            );
        } catch (ValidationException $exception) {
            return redirect()->route('checkout.show')
                ->withErrors($exception->errors())
                ->withInput($request->quoteInput());
        }
        $request->attributes->set('cartItemCount', count($quote->lines));

        return view('checkout.show', [
            'cart' => null,
            'quote' => $quote,
            'form' => $request->quoteInput(),
        ]);
    }
}
