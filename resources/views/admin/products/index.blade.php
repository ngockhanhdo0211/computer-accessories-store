@extends('layouts.workspace')
@section('title', 'Quản lý sản phẩm')
@section('content')
<div class="shell admin-page">
    <header class="admin-heading">
        <div>
            <p class="eyebrow">Hàng hóa</p>
            <h1>Quản lý sản phẩm</h1>
            <p><strong>{{ $products->total() }}</strong> sản phẩm trong kết quả hiện tại. Cập nhật nội dung bán hàng tại đây; số lượng được quản lý trong khu vực Tồn kho.</p>
        </div>
        <a class="button" href="{{ route('admin.products.create') }}">Tạo sản phẩm</a>
    </header>

    @if ($errors->has('product'))
        <div class="alert alert--error" role="alert">{{ $errors->first('product') }}</div>
    @endif

    <form class="catalog-filter admin-product-filter" method="GET" action="{{ route('admin.products.index') }}" aria-label="Lọc sản phẩm quản trị">
        <div class="field">
            <label for="admin-search">Tìm theo tên hoặc SKU</label>
            <input id="admin-search" name="search" type="search" value="{{ $filters['search'] ?? '' }}">
        </div>
        <div class="field">
            <label for="admin-category">Danh mục</label>
            <select id="admin-category" name="category">
                <option value="">Tất cả</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}" @selected(($filters['category'] ?? null) === $category->id)>{{ $category->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="field">
            <label for="admin-brand">Thương hiệu</label>
            <select id="admin-brand" name="brand">
                <option value="">Tất cả</option>
                @foreach ($brands as $brand)
                    <option value="{{ $brand->id }}" @selected(($filters['brand'] ?? null) === $brand->id)>{{ $brand->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="field">
            <label for="admin-visibility">Trạng thái</label>
            <select id="admin-visibility" name="visibility">
                <option value="">Tất cả</option>
                <option value="active" @selected(($filters['visibility'] ?? null) === 'active')>Đang hiển thị</option>
                <option value="hidden" @selected(($filters['visibility'] ?? null) === 'hidden')>Đang ẩn</option>
            </select>
        </div>
        <div class="field">
            <label for="admin-sort">Sắp xếp</label>
            <select id="admin-sort" name="sort">
                <option value="name" @selected(($filters['sort'] ?? 'name') === 'name')>Tên A–Z</option>
                <option value="newest" @selected(($filters['sort'] ?? null) === 'newest')>Mới nhất</option>
                <option value="price_asc" @selected(($filters['sort'] ?? null) === 'price_asc')>Giá tăng</option>
                <option value="price_desc" @selected(($filters['sort'] ?? null) === 'price_desc')>Giá giảm</option>
            </select>
        </div>
        <div class="catalog-filter__actions">
            <button class="button" type="submit">Áp dụng</button>
            <a class="button button--quiet" href="{{ route('admin.products.index') }}">Xóa lọc</a>
        </div>
    </form>

    @if ($products->total() === 0 && collect($filters)->filter(fn ($value, $key) => $key !== 'sort' && filled($value))->isEmpty())
        <section class="admin-empty">
            <h2>Chưa có sản phẩm</h2>
            <p>Tạo sản phẩm đầu tiên sau khi đã có danh mục và thương hiệu.</p>
            <a class="button button--outline" href="{{ route('admin.products.create') }}">Tạo sản phẩm đầu tiên</a>
        </section>
    @elseif ($products->isEmpty())
        <section class="admin-empty">
            <h2>Không tìm thấy sản phẩm</h2>
            <p>Thử thay đổi từ khóa hoặc bộ lọc.</p>
            <a class="button button--outline" href="{{ route('admin.products.index') }}">Xóa bộ lọc</a>
        </section>
    @else
        <div class="admin-product-list" role="list">
            @foreach ($products as $product)
                <article class="admin-product-row" role="listitem">
                    <div class="admin-product-row__image">
                        @if ($product->primaryImage)
                            <img src="{{ $product->primaryImage->url() }}" alt="{{ $product->primaryImage->alt_text ?: $product->name }}" data-image-fallback>
                            <span class="product-image-placeholder" hidden>Không tải được ảnh</span>
                        @else
                            <span class="product-image-placeholder">Chưa có ảnh</span>
                        @endif
                    </div>
                    <div class="admin-product-row__main">
                        <p class="admin-product-row__meta">{{ $product->sku }} · {{ $product->category->name }} · {{ $product->brand->name }}</p>
                        <h2>{{ $product->name }}</h2>
                        <p>{{ $product->formattedPrice() }} · Tồn bán được: {{ number_format($product->sellable_quantity, 0, ',', '.') }}</p>
                    </div>
                    <span class="status-tag {{ $product->visibility->value === 'active' ? 'status-tag--visible' : '' }}">{{ $product->visibility->label() }}</span>
                    <a class="button button--quiet" href="{{ route('admin.products.edit', $product) }}">Sửa</a>
                </article>
            @endforeach
        </div>
        {{ $products->links() }}
    @endif
</div>
@endsection
