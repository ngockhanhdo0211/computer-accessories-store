<?php

namespace App\Http\Controllers\Admin;

use App\Actions\UpdateShippingRate;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateShippingRateRequest;
use App\Models\ShippingRate;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class ShippingRateController extends Controller
{
    public function index(): View
    {
        $shippingRates = ShippingRate::query()->orderBy('region_key')->get();

        return view('admin.shipping-rates.index', compact('shippingRates'));
    }

    public function edit(ShippingRate $shippingRate): View
    {
        return view('admin.shipping-rates.edit', compact('shippingRate'));
    }

    public function update(
        UpdateShippingRateRequest $request,
        ShippingRate $shippingRate,
        UpdateShippingRate $action
    ): RedirectResponse {
        $shippingRate = $action->handle($shippingRate, $request->user(), $request->validated('fee_vnd'));

        return redirect()->route('admin.shipping-rates.edit', $shippingRate)
            ->with('status', 'Đã cập nhật phí vận chuyển.');
    }
}
