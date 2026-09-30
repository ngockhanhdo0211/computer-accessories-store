<?php

namespace App\Http\Controllers;

use App\Actions\BuildCheckoutQuote;
use App\Actions\BuildCodOrderFingerprint;
use App\Actions\CreateCodOrder;
use App\Actions\GetCartSummary;
use App\Http\Requests\CheckoutQuoteRequest;
use App\Http\Requests\StoreCodOrderRequest;
use App\Models\Coupon;
use App\Models\Order;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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
            'requestKey' => $this->requestKey($request),
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
        BuildCodOrderFingerprint $fingerprints,
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
        $requestKey = $request->requestKey() ?? (string) Str::uuid();
        $coupon = $quote->coupon === null ? null : Coupon::query()->findOrFail($quote->coupon->couponId);
        $fingerprint = $fingerprints->handle($request->user(), $requestKey, $quote, $coupon)['fingerprint'];
        $request->session()->put('checkout.cod_confirmation', [
            'user_id' => (int) $request->user()->id,
            'request_key' => $requestKey,
            'fingerprint' => $fingerprint,
        ]);

        return view('checkout.show', [
            'cart' => null,
            'quote' => $quote,
            'requestKey' => $requestKey,
            'form' => $request->quoteInput(),
        ]);
    }

    public function storeCod(StoreCodOrderRequest $request, CreateCodOrder $action): RedirectResponse
    {
        $confirmation = $request->session()->get('checkout.cod_confirmation');
        $fingerprint = is_array($confirmation)
            && ($confirmation['user_id'] ?? null) === (int) $request->user()->id
            && ($confirmation['request_key'] ?? null) === $request->requestKey()
            && is_string($confirmation['fingerprint'] ?? null)
                ? $confirmation['fingerprint']
                : Order::query()
                    ->where('user_id', $request->user()->id)
                    ->where('request_key', $request->requestKey())
                    ->value('idempotency_fingerprint');

        if (! is_string($fingerprint)
            || preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1) {
            return redirect()->route('checkout.show')
                ->withErrors(['request_key' => 'Báo giá xác nhận đã hết hiệu lực. Vui lòng tạo lại báo giá.'])
                ->withInput($request->checkoutInput());
        }

        try {
            $order = $action->handle(
                $request->user(),
                $request->recipient(),
                $request->requestKey(),
                $request->couponCode(),
                $fingerprint,
            );
        } catch (ValidationException $exception) {
            return redirect()->route('checkout.show')
                ->withErrors($exception->errors())
                ->withInput($request->checkoutInput());
        }

        return redirect()->route('orders.show', $order->order_code)
            ->with('status', 'Đơn hàng COD đã được tạo thành công.');
    }

    private function requestKey(Request $request): string
    {
        $old = $request->old('request_key');

        return is_string($old) && Str::isUuid($old) ? $old : (string) Str::uuid();
    }
}
