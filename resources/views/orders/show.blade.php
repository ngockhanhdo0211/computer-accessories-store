@extends('layouts.storefront')
@section('title', 'Đơn hàng '.$order->order_code)
@section('content')
@php
    $orderBadge = match($order->status) {
        \App\Enums\OrderStatus::Delivered => 'status-badge--success',
        \App\Enums\OrderStatus::Cancelled => 'status-badge--danger',
        \App\Enums\OrderStatus::InTransit => 'status-badge--info',
        default => 'status-badge--warning',
    };
@endphp
<div class="shell order-page order-detail-page">
    <a class="text-link order-back-link" href="{{ route('orders.index') }}">← Đơn hàng của tôi</a>
    <header class="order-detail-header">
        <div><p class="eyebrow">Chi tiết đơn hàng</p><h1>{{ $order->order_code }}</h1><p>Đặt lúc <time datetime="{{ $order->created_at->toIso8601String() }}">{{ $order->created_at->timezone('Asia/Ho_Chi_Minh')->format('H:i · d/m/Y') }}</time></p></div>
        <div class="order-detail-header__status"><span class="status-badge {{ $orderBadge }}">{{ $order->status->label() }}</span><span>{{ $order->payment_status->label() }}</span></div>
    </header>

    <div class="order-detail-layout">
        <div class="order-detail-main">
            <section class="order-panel" aria-labelledby="order-items-title"><header class="order-panel__heading"><div><p class="section-label">Sản phẩm đã đặt</p><h2 id="order-items-title">Chi tiết sản phẩm</h2></div><span>{{ $order->items->sum('quantity') }} sản phẩm</span></header><div class="order-item-list">
                @foreach($order->items as $item)
                    <article class="order-item-row"><div><h3>{{ $item->product_name }}</h3><p>SKU {{ $item->sku }} · {{ $item->quantity }} × {{ number_format($item->unit_price_vnd, 0, ',', '.') }} ₫</p>@if($item->discount_vnd > 0)<p>Giảm tại thời điểm mua: {{ number_format($item->discount_vnd, 0, ',', '.') }} ₫</p>@endif</div><strong>{{ number_format($item->line_total_vnd, 0, ',', '.') }} ₫</strong></article>
                @endforeach
            </div></section>

            <section class="order-panel" aria-labelledby="recipient-title"><header class="order-panel__heading"><div><p class="section-label">Thông tin giao hàng đã lưu</p><h2 id="recipient-title">Người nhận</h2></div></header><dl class="order-data-list"><div><dt>Họ tên</dt><dd>{{ $order->recipient_name }}</dd></div><div><dt>Điện thoại</dt><dd>{{ $order->recipient_phone }}</dd></div><div><dt>Email</dt><dd>{{ $order->recipient_email }}</dd></div><div><dt>Khu vực</dt><dd>{{ $order->recipient_region }}</dd></div><div class="order-data-list__wide"><dt>Địa chỉ</dt><dd>{{ $order->recipient_address }}</dd></div></dl></section>

            <section class="order-panel" aria-labelledby="history-title"><header class="order-panel__heading"><div><p class="section-label">Tiến trình</p><h2 id="history-title">Lịch sử trạng thái</h2></div></header><ol class="order-timeline">@foreach($order->statusHistories as $history)<li><span class="order-timeline__marker" aria-hidden="true"></span><div><strong>{{ $history->to_status->label() }}</strong><time datetime="{{ $history->created_at->toIso8601String() }}">{{ $history->created_at->timezone('Asia/Ho_Chi_Minh')->format('H:i · d/m/Y') }}</time></div></li>@endforeach</ol></section>
        </div>

        <aside class="order-summary" aria-labelledby="order-summary-title"><header><p class="section-label">Thanh toán</p><h2 id="order-summary-title">Tóm tắt đơn hàng</h2></header><dl class="checkout-totals"><div><dt>Phương thức</dt><dd>{{ $order->payment_method->label() }}</dd></div><div><dt>Tạm tính</dt><dd>{{ number_format($order->items_subtotal_vnd, 0, ',', '.') }} ₫</dd></div><div><dt>Giảm sản phẩm</dt><dd>− {{ number_format($order->item_discount_vnd, 0, ',', '.') }} ₫</dd></div><div><dt>Phí vận chuyển</dt><dd>{{ number_format($order->shipping_fee_vnd, 0, ',', '.') }} ₫</dd></div><div><dt>Giảm vận chuyển</dt><dd>− {{ number_format($order->shipping_discount_vnd, 0, ',', '.') }} ₫</dd></div><div><dt>Tổng ưu đãi</dt><dd>− {{ number_format($order->total_discount_vnd, 0, ',', '.') }} ₫</dd></div>@if($order->coupon_snapshot_json)<div><dt>Mã giảm giá</dt><dd>{{ $order->coupon_snapshot_json['code'] }}</dd></div>@endif<div class="checkout-totals__grand"><dt>Tổng cộng</dt><dd>{{ number_format($order->total_vnd, 0, ',', '.') }} ₫</dd></div></dl><a class="button button--quiet" href="{{ route('products.index') }}">Tiếp tục mua sắm</a></aside>
    </div>

    <section class="order-panel" aria-labelledby="cancellation-title">
        <p class="section-label">Hỗ trợ đơn hàng</p>
        <h2 id="cancellation-title">Yêu cầu hủy đơn</h2>
        @if($order->cancellationRequest)
            <p><span class="status-badge status-badge--{{ $order->cancellationRequest->status === \App\Enums\OrderCancellationRequestStatus::Approved ? 'success' : ($order->cancellationRequest->status === \App\Enums\OrderCancellationRequestStatus::Rejected ? 'danger' : 'warning') }}">{{ $order->cancellationRequest->status->label() }}</span></p>
            <dl class="order-data-list"><div class="order-data-list__wide"><dt>Lý do</dt><dd>{{ $order->cancellationRequest->reason }}</dd></div><div><dt>Gửi lúc</dt><dd>{{ $order->cancellationRequest->created_at->timezone('Asia/Ho_Chi_Minh')->format('H:i · d/m/Y') }}</dd></div>@if($order->cancellationRequest->review_note)<div class="order-data-list__wide"><dt>Phản hồi</dt><dd>{{ $order->cancellationRequest->review_note }}</dd></div>@endif</dl>
        @elseif($order->status === \App\Enums\OrderStatus::Placed)
            <p>Nhân viên sẽ xem xét yêu cầu. Việc gửi yêu cầu chưa làm thay đổi trạng thái đơn.</p>
            <form method="POST" action="{{ route('orders.cancellation-request.store', $order->order_code) }}" data-submit-once data-confirm-action="Gửi yêu cầu hủy đơn này?">
                @csrf
                <input type="hidden" name="request_key" value="{{ old('request_key', (string) \Illuminate\Support\Str::uuid()) }}">
                <div class="field"><label for="cancellation-reason">Lý do hủy</label><textarea id="cancellation-reason" name="reason" rows="4" maxlength="500" required>{{ old('reason') }}</textarea>@error('reason', 'cancellationRequest')<p class="field-error" role="alert">{{ $message }}</p>@enderror</div>
                @foreach(['order', 'request_key', 'request', 'authorization'] as $field) @error($field, 'cancellationRequest')<p class="field-error" role="alert">{{ $message }}</p>@enderror @endforeach
                <button class="button button--quiet" type="submit">Yêu cầu hủy đơn</button>
            </form>
        @else
            <p>Đơn ở trạng thái hiện tại không thể gửi yêu cầu hủy.</p>
        @endif
    </section>
</div>
@endsection
