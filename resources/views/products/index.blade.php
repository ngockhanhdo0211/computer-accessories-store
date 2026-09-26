@extends('layouts.storefront')
@section('title', 'Sản phẩm')
@section('content')
<div class="catalog-page">
    <header class="shell catalog-intro">
        <p class="eyebrow">Catalog phụ kiện</p>
        <h1>Chọn đúng phụ kiện cho góc làm việc.</h1>
        <p>Tìm theo tên, SKU, danh mục hoặc thương hiệu. Giá và tồn kho hiển thị từ dữ liệu hiện tại.</p>
    </header>

    <div class="shell catalog-layout">
        <aside class="catalog-sidebar">
            <form class="catalog-filter" method="GET" action="{{ route('products.index') }}" aria-label="Tìm và lọc sản phẩm">
                <div class="field">
                    <label for="catalog-search">Tìm sản phẩm</label>
                    <input id="catalog-search" name="search" type="search" maxlength="100" value="{{ $filters['search'] ?? '' }}" placeholder="Tên hoặc SKU">
                </div>
                <div class="field">
                    <label for="catalog-category">Danh mục</label>
                    <select id="catalog-category" name="category">
                        <option value="">Tất cả danh mục</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}" @selected(($filters['category'] ?? null) === $category->id)>{{ $category->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="catalog-brand">Thương hiệu</label>
                    <select id="catalog-brand" name="brand">
                        <option value="">Tất cả thương hiệu</option>
                        @foreach ($brands as $brand)
                            <option value="{{ $brand->id }}" @selected(($filters['brand'] ?? null) === $brand->id)>{{ $brand->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="catalog-sort">Sắp xếp</label>
                    <select id="catalog-sort" name="sort">
                        <option value="newest" @selected(($filters['sort'] ?? 'newest') === 'newest')>Mới nhất</option>
                        <option value="price_asc" @selected(($filters['sort'] ?? null) === 'price_asc')>Giá tăng dần</option>
                        <option value="price_desc" @selected(($filters['sort'] ?? null) === 'price_desc')>Giá giảm dần</option>
                        <option value="name" @selected(($filters['sort'] ?? null) === 'name')>Tên A–Z</option>
                    </select>
                </div>
                <div class="catalog-filter__actions">
                    <button class="button" type="submit">Xem kết quả</button>
                    <a class="text-link" href="{{ route('products.index') }}">Xóa bộ lọc</a>
                </div>
            </form>
        </aside>

        <section class="catalog-results" aria-labelledby="catalog-results-title">
            <header class="catalog-results__head">
                <div>
                    <p class="section-label">Kết quả</p>
                    <h2 id="catalog-results-title">{{ $products->total() }} sản phẩm</h2>
                </div>
                <p>Chỉ hiển thị sản phẩm, danh mục và thương hiệu đang hoạt động.</p>
            </header>

            @if ($products->isEmpty())
                <div class="catalog-empty">
                    <h3>{{ $products->total() === 0 ? 'Chưa có sản phẩm phù hợp' : 'Không có sản phẩm ở trang này' }}</h3>
                    <p>Thử thay đổi từ khóa hoặc bộ lọc để xem kết quả khác.</p>
                    <a class="button button--outline" href="{{ route('products.index') }}">Xem toàn bộ catalog</a>
                </div>
            @else
                <div class="product-grid">
                    @foreach ($products as $product)
                        <article class="product-card">
                            <a class="product-card__image" href="{{ route('products.show', $product) }}" aria-label="Xem {{ $product->name }}">
                                @if ($product->primaryImage)
                                    <img src="{{ $product->primaryImage->url() }}" alt="{{ $product->primaryImage->alt_text ?: $product->name }}" data-image-fallback>
                                    <span class="product-image-placeholder" hidden>Không tải được ảnh</span>
                                @else
                                    <span class="product-image-placeholder">Chưa có ảnh</span>
                                @endif
                            </a>
                            <div class="product-card__body">
                                <p class="product-card__meta">{{ $product->category->name }} · {{ $product->brand->name }}</p>
                                <h3><a href="{{ route('products.show', $product) }}">{{ $product->name }}</a></h3>
                                <p class="product-card__price">{{ $product->formattedPrice() }}</p>
                                <p class="product-card__stock">{{ $product->isInStock() ? 'Còn hàng' : 'Tạm hết hàng' }}</p>
                                <a class="text-link" href="{{ route('products.show', $product) }}">Xem chi tiết</a>
                            </div>
                        </article>
                    @endforeach
                </div>
                {{ $products->links() }}
            @endif
        </section>
    </div>
</div>
@endsection