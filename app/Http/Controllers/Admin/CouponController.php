<?php

namespace App\Http\Controllers\Admin;

use App\Actions\DeleteCoupon;
use App\Actions\SaveCoupon;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCouponRequest;
use App\Http\Requests\UpdateCouponRequest;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Product;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class CouponController extends Controller
{
    public function index(): View|RedirectResponse
    {
        $coupons = Coupon::query()
            ->withCount(['products', 'categories', 'brands'])
            ->orderByDesc('created_at')->orderByDesc('id')->paginate(25);

        if ($coupons->isEmpty() && $coupons->total() > 0) {
            return redirect()->route('admin.coupons.index', ['page' => $coupons->lastPage()]);
        }

        return view('admin.coupons.index', compact('coupons'));
    }

    public function create(): View
    {
        return view('admin.coupons.create', $this->formData());
    }

    public function store(StoreCouponRequest $request, SaveCoupon $action): RedirectResponse
    {
        $coupon = $action->handle($request->validated(), $request->user());

        return redirect()->route('admin.coupons.edit', $coupon)->with('status', 'Đã tạo mã giảm giá.');
    }

    public function edit(Coupon $coupon): View
    {
        return view('admin.coupons.edit', array_merge(
            $this->formData(),
            ['coupon' => $coupon, 'selectedTargets' => $coupon->targetIds()],
        ));
    }

    public function update(UpdateCouponRequest $request, Coupon $coupon, SaveCoupon $action): RedirectResponse
    {
        $coupon = $action->handle($request->validated(), $request->user(), $coupon);

        return redirect()->route('admin.coupons.edit', $coupon)->with('status', 'Đã cập nhật mã giảm giá.');
    }

    public function destroy(Coupon $coupon, DeleteCoupon $action): RedirectResponse
    {
        $action->handle($coupon, request()->user());

        return redirect()->route('admin.coupons.index')->with('status', 'Đã xóa mã giảm giá chưa được sử dụng.');
    }

    /** @return array<string, mixed> */
    private function formData(): array
    {
        return [
            'products' => Product::query()->orderBy('name')->orderBy('id')->get(['id', 'name', 'sku']),
            'categories' => Category::query()->orderBy('name')->orderBy('id')->get(['id', 'name']),
            'brands' => Brand::query()->orderBy('name')->orderBy('id')->get(['id', 'name']),
        ];
    }
}
