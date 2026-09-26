<?php

namespace App\Http\Controllers\Admin;

use App\Actions\AddProductImages;
use App\Actions\DeleteProductImage;
use App\Actions\ReorderProductImages;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReorderProductImagesRequest;
use App\Http\Requests\UploadProductImagesRequest;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\RedirectResponse;

class ProductImageController extends Controller
{
    public function store(UploadProductImagesRequest $request, Product $product, AddProductImages $action): RedirectResponse
    {
        $action->handle($product, $request->file('images', []), $request->input('image_alt_texts', []));

        return redirect()->route('admin.products.edit', $product)->with('status', 'Đã thêm ảnh sản phẩm.');
    }

    public function update(ReorderProductImagesRequest $request, Product $product, ReorderProductImages $action): RedirectResponse
    {
        $action->handle(
            $product,
            $request->validated('ordered_image_ids'),
            (int) $request->validated('primary_image_id')
        );

        return redirect()->route('admin.products.edit', $product)->with('status', 'Đã cập nhật thứ tự và ảnh đại diện.');
    }

    public function destroy(Product $product, ProductImage $image, DeleteProductImage $action): RedirectResponse
    {
        $action->handle($product, $image);

        return redirect()->route('admin.products.edit', $product)->with('status', 'Đã xóa ảnh sản phẩm.');
    }
}
