@extends('layouts.storefront')
@section('title', 'Đơn hàng '.$order->order_code)
@section('content')
<div class="checkout-page shell">
    <header class="checkout-header">
        <div>
            <p class="eyebrow">Đặt hàng thành công</p>
            <h1>Đơn hàng {{ $order->order_code }}</h1>
        </div>
        <p>Chúng tôi đã ghi nhận đơn COD. Bạn thanh toán khi nhận hàng; trạng thái hiện tại là {{ $order->status->label() }}.</p>
    </header>

    <div class="checkout-layout">
        <section class="form-panel" aria-labelledby="receipt-recipient-title">
            <header class="checkout-section-heading">
                <p class="section-label">Thông tin giao hàng</p>
                <h2 id="receipt-recipient-title">{{ $order->recipient_name }}</h2>
                <p>{{ $order->recipient_phone }} · {{ $order->recipient_email }}</p>
            </header>
            <p>{{ $order->recipient_address }}</p>

            <div class="checkout-summary-lines">
                @foreach ($order->items as $item)
                    <article class="checkout-summary-line">
                        <div>
                            <h3>{{ $item->product_name }}</h3>
                            <p>SKU {{ $item->sku }} · {{ $item->quantity }} × {{ number_format($item->unit_price_vnd, 0, ',', '.') }} ₫</p>
                            @if ($item->discount_vnd > 0)
                                <p>Giảm {{ number_format($item->discount_vnd, 0, ',', '.') }} ₫</p>
                            @endif
                        </div>
                        <strong>{{ number_format($item->line_total_vnd, 0, ',', '.') }} ₫</strong>
                    </article>
                @endforeach
            </div>

            <div class="checkout-form-actions">
                <a class="button button--quiet" href="{{ route('products.index') }}">Tiếp tục mua sắm</a>
            </div>
        </section>

        <aside class="checkout-summary" aria-labelledby="receipt-total-title">
            <header class="checkout-section-heading">
                <p class="section-label">Thanh toán khi nhận hàng</p>
                <h2 id="receipt-total-title">Tóm tắt đơn hàng</h2>
            </header>
            <dl class="checkout-totals">
                <div><dt>Tạm tính</dt><dd>{{ number_format($order->items_subtotal_vnd, 0, ',', '.') }} ₫</dd></div>
                <div><dt>Giảm sản phẩm</dt><dd>− {{ number_format($order->item_discount_vnd, 0, ',', '.') }} ₫</dd></div>
                <div><dt>Phí vận chuyển</dt><dd>{{ number_format($order->shipping_fee_vnd, 0, ',', '.') }} ₫</dd></div>
                <div><dt>Giảm vận chuyển</dt><dd>− {{ number_format($order->shipping_discount_vnd, 0, ',', '.') }} ₫</dd></div>
                @if ($order->coupon_snapshot_json)
                    <div><dt>Mã giảm giá</dt><dd>{{ $order->coupon_snapshot_json['code'] }}</dd></div>
                @endif
                <div><dt>Trạng thái</dt><dd>{{ $order->status->label() }}</dd></div>
                <div class="checkout-totals__grand"><dt>Tổng COD</dt><dd>{{ number_format($order->total_vnd, 0, ',', '.') }} ₫</dd></div>
            </dl>
            <p class="checkout-summary__note">Đơn được tạo lúc {{ $order->created_at->timezone('Asia/Ho_Chi_Minh')->format('H:i d/m/Y') }}.</p>
        </aside>
    </div>
</div>
@endsection
