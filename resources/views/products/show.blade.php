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
            <p class="eyebrow">{{ $product->category->name }} / {{ $product->brand->name }}</p>
            <h1>{{ $product->name }}</h1>
            <p class="product-detail__sku">SKU {{ $product->sku }}</p>
            <p class="product-detail__price">{{ $product->formattedPrice() }}</p>
            @if ($product->sale_price_vnd !== null)
                <p class="product-detail__original-price">Giá gốc: <s>{{ number_format($product->price_vnd, 0, ',', '.') }} ₫</s></p>
            @endif
            <p class="product-detail__stock">{{ $product->isInStock() ? 'Còn hàng' : 'Tạm hết hàng' }}</p>
            <p class="product-detail__summary">{{ $product->short_description }}</p>
            <div class="product-detail__description">
                <h2>Thông tin sản phẩm</h2>
                <p>{!! nl2br(e($product->description)) !!}</p>
            </div>
            <a class="button button--outline" href="{{ route('products.index') }}">Quay lại catalog</a>
        </section>
    </div>
</div>
@endsection