<?php

namespace App\Http\Controllers\Admin;

use App\Actions\AddProductImages;
use App\Actions\DeleteProduct;
use App\Actions\SaveProduct;
use App\Enums\ProductVisibility;
use App\Http\Controllers\Controller;
use App\Http\Requests\ProductIndexRequest;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;

class ProductController extends Controller
{
    public function index(ProductIndexRequest $request): View|RedirectResponse
    {
        $filters = $request->validated();
        $products = Product::query()
            ->with(['category:id,name,slug', 'brand:id,name,slug', 'primaryImage:id,product_id,storage_provider,path,cloudinary_public_id,secure_url,width,height,bytes,format,alt_text'])
            ->search($filters['search'] ?? null)
            ->forCategory($filters['category'] ?? null)
            ->forBrand($filters['brand'] ?? null)
            ->when($filters['visibility'] ?? null, fn ($query, $visibility) => $query->where('visibility', $visibility));

        $this->applySort($products, $filters['sort']);
        $products = $products->paginate(24)->withQueryString();

        if ($products->isEmpty() && $products->total() > 0) {
            return redirect()->route('admin.products.index', [
                ...$request->safe()->except('page'),
                'page' => $products->lastPage(),
            ]);
        }

        return view('admin.products.index', [
            'products' => $products,
            'categories' => Category::query()->orderBy('name')->get(['id', 'name']),
            'brands' => Brand::query()->orderBy('name')->get(['id', 'name']),
            'filters' => $filters,
        ]);
    }

    public function create(): View
    {
        return view('admin.products.create', $this->formOptions());
    }

    public function store(StoreProductRequest $request, SaveProduct $saveProduct, AddProductImages $addImages): RedirectResponse
    {
        $product = $saveProduct->handle($request->validated());
        $addImages->handle($product, $request->file('images', []), $request->input('image_alt_texts', []));

        return redirect()->route('admin.products.edit', $product)->with('status', 'Đã tạo sản phẩm.');
    }

    public function edit(Product $product): View
    {
        $product->load('images');

        return view('admin.products.edit', [
            ...$this->formOptions(),
            'product' => $product,
        ]);
    }

    public function update(UpdateProductRequest $request, Product $product, SaveProduct $saveProduct, AddProductImages $addImages): RedirectResponse
    {
        $product = $saveProduct->handle($request->validated(), $product);
        $addImages->handle($product, $request->file('images', []), $request->input('image_alt_texts', []));

        return redirect()->route('admin.products.edit', $product)->with('status', 'Đã cập nhật sản phẩm.');
    }

    public function destroy(Product $product, DeleteProduct $action): RedirectResponse
    {
        $action->handle($product);

        return redirect()->route('admin.products.index')->with('status', 'Đã xóa sản phẩm.');
    }

    private function formOptions(): array
    {
        return [
            'categories' => Category::query()->orderBy('name')->get(['id', 'name', 'is_visible']),
            'brands' => Brand::query()->orderBy('name')->get(['id', 'name', 'is_visible']),
            'visibilities' => ProductVisibility::cases(),
        ];
    }

    private function applySort(Builder $query, string $sort): void
    {
        match ($sort) {
            'newest' => $query->orderByDesc('created_at')->orderByDesc('id'),
            'price_asc' => $query->orderByRaw('COALESCE(sale_price_vnd, price_vnd) ASC')->orderBy('id'),
            'price_desc' => $query->orderByRaw('COALESCE(sale_price_vnd, price_vnd) DESC')->orderBy('id'),
            default => $query->orderBy('name')->orderBy('id'),
        };
    }
}
