<?php

namespace App\Http\Controllers;

use App\Http\Requests\CatalogRequest;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;

class CatalogController extends Controller
{
    public function index(CatalogRequest $request): View|RedirectResponse
    {
        $filters = $request->validated();
        $products = Product::query()
            ->publiclyVisible()
            ->with(['category:id,name,slug', 'brand:id,name,slug', 'primaryImage:id,product_id,path,alt_text'])
            ->search($filters['search'] ?? null)
            ->forCategory($filters['category'] ?? null)
            ->forBrand($filters['brand'] ?? null);

        $this->applySort($products, $filters['sort']);
        $products = $products->paginate(20)->withQueryString();

        if ($products->isEmpty() && $products->total() > 0) {
            return redirect()->route('products.index', [
                ...$request->safe()->except('page'),
                'page' => $products->lastPage(),
            ]);
        }

        return view('products.index', [
            'products' => $products,
            'categories' => Category::query()->where('is_visible', true)->orderBy('name')->get(['id', 'name']),
            'brands' => Brand::query()->where('is_visible', true)->orderBy('name')->get(['id', 'name']),
            'filters' => $filters,
        ]);
    }

    public function show(Product $product): View
    {
        $product = Product::query()->publiclyVisible()
            ->with(['category:id,name,slug', 'brand:id,name,slug', 'images'])
            ->whereKey($product->id)
            ->firstOrFail();

        return view('products.show', compact('product'));
    }

    private function applySort(Builder $query, string $sort): void
    {
        match ($sort) {
            'price_asc' => $query->orderByRaw('COALESCE(sale_price_vnd, price_vnd) ASC')->orderBy('id'),
            'price_desc' => $query->orderByRaw('COALESCE(sale_price_vnd, price_vnd) DESC')->orderBy('id'),
            'name' => $query->orderBy('name')->orderBy('id'),
            default => $query->orderByDesc('created_at')->orderByDesc('id'),
        };
    }
}
