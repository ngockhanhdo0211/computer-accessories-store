<?php

namespace App\Http\Controllers\Admin;

use App\Actions\DeleteBrand;
use App\Actions\SaveBrand;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBrandRequest;
use App\Http\Requests\UpdateBrandRequest;
use App\Models\Brand;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class BrandController extends Controller
{
    public function index(): View|RedirectResponse
    {
        $brands = Brand::query()->orderBy('name')->orderBy('id')->paginate(25);

        if ($brands->isEmpty() && $brands->total() > 0) {
            return redirect()->route('admin.brands.index', ['page' => $brands->lastPage()]);
        }

        return view('admin.brands.index', compact('brands'));
    }

    public function create(): View
    {
        return view('admin.brands.create');
    }

    public function store(StoreBrandRequest $request, SaveBrand $action): RedirectResponse
    {
        $action->handle($request->validated());

        return redirect()->route('admin.brands.index')->with('status', 'Đã tạo thương hiệu.');
    }

    public function edit(Brand $brand): View
    {
        return view('admin.brands.edit', compact('brand'));
    }

    public function update(UpdateBrandRequest $request, Brand $brand, SaveBrand $action): RedirectResponse
    {
        $brand = $action->handle($request->validated(), $brand);

        return redirect()->route('admin.brands.edit', $brand)->with('status', 'Đã cập nhật thương hiệu.');
    }

    public function destroy(Brand $brand, DeleteBrand $action): RedirectResponse
    {
        $action->handle($brand);

        return redirect()->route('admin.brands.index')->with('status', 'Đã xóa thương hiệu.');
    }
}
