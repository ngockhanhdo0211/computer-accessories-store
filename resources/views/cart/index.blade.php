@extends('layouts.storefront')
@section('title', 'Giỏ hàng')
@section('content')
<div class="cart-page shell">
    <header class="cart-header">
        <div>
            <h1>Giỏ hàng</h1>
        </div>
        <p>Kiểm tra sản phẩm, số lượng và thành tiền trước khi nhận báo giá giao hàng.</p>
    </header>

    @error('cart')
        <div class="alert alert--error" role="alert">{{ $message }}</div>
    @enderror

    @if ($items->isEmpty())
        <section class="cart-empty" aria-labelledby="empty-cart-title">
            <p class="section-label">Chưa có sản phẩm</p>
            <h2 id="empty-cart-title">Giỏ hàng đang trống.</h2>
            <p>Khám phá cửa hàng và chọn phụ kiện phù hợp với góc làm việc của bạn.</p>
            <a class="button" href="{{ route('products.index') }}">Xem sản phẩm</a>
        </section>
    @else
        <div class="cart-layout">
            <section class="cart-lines" aria-label="Sản phẩm trong giỏ">
                @foreach ($items as $row)
                    <article class="cart-line">
                        @if ($row['isPublic'])
                            <a class="cart-line__image" href="{{ route('products.show', $row['product']) }}">
                        @else
                            <div class="cart-line__image">
                        @endif
                            @if ($row['product']->primaryImage)
                                <img src="{{ $row['product']->primaryImage->url() }}" alt="{{ $row['product']->primaryImage->alt_text ?: $row['product']->name }}" data-image-fallback>
                                <span class="product-image-placeholder" hidden>Không tải được ảnh</span>
                            @else
                                <span class="product-image-placeholder">Chưa có ảnh</span>
                            @endif
                        @if ($row['isPublic'])
                            </a>
                        @else
                            </div>
                        @endif

                        <div class="cart-line__main">
                            <p class="cart-line__meta">{{ $row['product']->category?->name }} · {{ $row['product']->brand?->name }}</p>
                            <h2>{{ $row['product']->name }}</h2>
                            <p class="cart-line__sku">SKU {{ $row['product']->sku }}</p>
                            <p class="cart-line__price">{{ number_format($row['unitPrice'], 0, ',', '.') }} ₫ / sản phẩm</p>

                            @if ($row['hasMoneyOverflow'])
                                <p class="cart-line__warning" role="alert">Giá trị dòng giỏ vượt giới hạn hỗ trợ và không được tính vào tổng.</p>
                            @elseif (! $row['isPublic'])
                                <p class="cart-line__warning" role="alert">Sản phẩm đã bị ẩn và không được tính vào tổng.</p>
                            @elseif (! $row['isPurchasable'])
                                <p class="cart-line__warning" role="alert">Chỉ còn {{ $row['availableQuantity'] }} sản phẩm khả dụng. Hãy giảm số lượng.</p>
                            @else
                                <p class="cart-line__stock">Còn {{ $row['availableQuantity'] }} sản phẩm khả dụng</p>
                            @endunless
                        </div>

                        <div class="cart-line__controls">
                            <form method="POST" action="{{ route('cart.items.update', $row['cartItem']) }}">
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="cart_item_id" value="{{ $row['cartItem']->id }}">
                                <label for="quantity-{{ $row['cartItem']->id }}">Số lượng</label>
                                <div class="cart-quantity">
                                    <input id="quantity-{{ $row['cartItem']->id }}" name="quantity" type="number" min="1" max="{{ max(1, $row['availableQuantity']) }}" value="{{ old('quantity', $row['cartItem']->quantity) }}" inputmode="numeric" required>
                                    <button class="button button--outline" type="submit">Cập nhật</button>
                                </div>
                                @if ((int) old('cart_item_id') === $row['cartItem']->id)
                                    @error('quantity')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                                    @error('product')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                                @endif
                            </form>
                            <form method="POST" action="{{ route('cart.items.destroy', $row['cartItem']) }}" data-confirm-delete data-confirm-delete-message="Xóa sản phẩm này khỏi giỏ hàng?">
                                @csrf
                                @method('DELETE')
                                <button class="link-button cart-remove" type="submit">Xóa</button>
                            </form>
                        </div>

                        <p class="cart-line__subtotal">
                            <span>Tạm tính</span>
                            <strong>{{ $row['subtotal'] === null ? 'Không thể tính' : number_format($row['subtotal'], 0, ',', '.').' ₫' }}</strong>
                        </p>
                    </article>
                @endforeach


            </section>

            <aside class="cart-summary" aria-labelledby="cart-summary-title">
                <p class="section-label">Tóm tắt</p>
                <h2 id="cart-summary-title">Giá trị sản phẩm</h2>
                <dl>
                    <div><dt>Sản phẩm hợp lệ</dt><dd>{{ $items->where('isPurchasable', true)->count() }}</dd></div>
                    <div class="cart-summary__total"><dt>Tổng tạm tính</dt><dd>{{ $total_overflow ? 'Vượt giới hạn hỗ trợ' : number_format($total_vnd, 0, ',', '.').' ₫' }}</dd></div>
                </dl>
                @if ($total_overflow)
                    <p class="cart-line__warning" role="alert">Tổng giỏ hàng quá lớn để tính an toàn. Hãy giảm số lượng trước khi tiếp tục.</p>
                @endif
                <p>Tổng tạm tính dùng giá sản phẩm hiện tại và sẽ được kiểm tra lại ở bước tiếp theo.</p>
                @if (! $total_overflow && $items->isNotEmpty() && $items->where('isPurchasable', true)->count() === $items->count())
                    <a class="button" href="{{ route('checkout.show') }}">Tiếp tục đến báo giá</a>
                @endif
                <a class="button button--outline" href="{{ route('products.index') }}">Tiếp tục mua sắm</a>
            </aside>
        </div>
    @endif
</div>
@endsection
