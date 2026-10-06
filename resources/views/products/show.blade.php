@extends('layouts.storefront')
@section('title', $product->name)
@section('content')
<div class="product-detail shell">
    <nav class="breadcrumbs" aria-label="Đường dẫn">
        <a href="{{ route('home') }}">Trang chủ</a>
        <span aria-hidden="true">/</span>
        <a href="{{ route('products.index') }}">Sản phẩm</a>
        <span aria-hidden="true">/</span>
        <span aria-current="page">{{ $product->name }}</span>
    </nav>

    <div class="product-detail__layout">
        <section class="product-gallery" aria-label="Ảnh sản phẩm">
            @if ($product->images->isEmpty())
                <div class="product-gallery__empty product-image-placeholder">Sản phẩm chưa có ảnh</div>
            @else
                @foreach ($product->images as $image)
                    <figure class="{{ $image->is_primary ? 'product-gallery__primary' : '' }}">
                        <div class="product-image-frame">
                            <img src="{{ $image->url() }}" alt="{{ $image->alt_text ?: $product->name }}" data-image-fallback>
                            <span class="product-image-placeholder" hidden>Không tải được ảnh</span>
                        </div>
                        @if ($image->alt_text)<figcaption>{{ $image->alt_text }}</figcaption>@endif
                    </figure>
                @endforeach
            @endif
        </section>

        <section class="product-detail__info">
            <p class="product-detail__meta">{{ $product->category->name }} · {{ $product->brand->name }}</p>
            <h1>{{ $product->name }}</h1>
            <p class="product-detail__sku">SKU {{ $product->sku }}</p>
            <p class="product-detail__price">{{ $product->formattedPrice() }}</p>
            @if ($product->hasValidSalePrice())
                <p class="product-detail__original-price">Giá gốc: <s>{{ number_format($product->price_vnd, 0, ',', '.') }} ₫</s></p>
            @endif
            <p class="product-detail__stock"><span class="product-stock-dot--{{ $availableQuantity > 0 ? 'available' : 'unavailable' }}" aria-hidden="true"></span>{{ $availableQuantity > 0 ? "Còn hàng · {$availableQuantity} sản phẩm khả dụng" : 'Tạm hết hàng' }}</p>

            @if ($availableQuantity > 0)
                @auth
                    @if (auth()->user()->isCustomer())
                        <form class="product-cart-form" method="POST" action="{{ route('cart.items.store', $product) }}">
                            @csrf
                            <div class="field">
                                <label for="product-quantity">Số lượng</label>
                                <input id="product-quantity" name="quantity" type="number" min="1" max="{{ $availableQuantity }}" value="{{ old('quantity', 1) }}" inputmode="numeric" required>
                                @error('quantity')<span class="field-error" role="alert">{{ $message }}</span>@enderror
                                @error('product')<span class="field-error" role="alert">{{ $message }}</span>@enderror
                            </div>
                            <button class="button" type="submit">Thêm vào giỏ</button>
                        </form>
                    @endif
                @else
                    <a class="button product-cart-login" href="{{ route('login') }}">Đăng nhập để thêm vào giỏ</a>
                @endauth
            @endif
            <p class="product-detail__summary">{{ $product->short_description }}</p>
            <div class="product-detail__description">
                <h2>Thông tin sản phẩm</h2>
                <p>{!! nl2br(e($product->description)) !!}</p>
            </div>
            <a class="button button--outline" href="{{ route('products.index') }}">Quay lại cửa hàng</a>
        </section>
    </div>
</div>
@endsection
