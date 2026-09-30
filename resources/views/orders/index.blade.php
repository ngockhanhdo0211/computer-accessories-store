@extends('layouts.storefront')
@section('title', 'Đơn hàng của tôi')
@section('content')
@php
    $hasActiveFilters = filled($filters['search'] ?? null)
        || filled($filters['status'] ?? null)
        || filled($filters['payment_status'] ?? null)
        || filled($filters['payment_method'] ?? null)
        || filled($filters['date_from'] ?? null)
        || filled($filters['date_to'] ?? null)
        || ($filters['sort'] ?? 'newest') !== 'newest';
@endphp
<div class="shell order-page">
    <header class="page-heading order-page__heading">
        <div>
            <p class="eyebrow">Tài khoản</p>
            <h1>Đơn hàng của tôi</h1>
            <p>Theo dõi các đơn đã đặt và xem lại thông tin mua hàng tại thời điểm thanh toán.</p>
        </div>
        @if ($orders->total() > 0)<p class="order-count"><strong>{{ $orders->total() }}</strong> đơn hàng</p>@endif
    </header>

    @if ($errors->any())
        <div class="alert alert--error order-filter-error" role="alert">Không thể áp dụng bộ lọc. Hãy kiểm tra các trường được đánh dấu bên dưới.</div>
    @endif
    <form class="order-filter" method="GET" action="{{ route('orders.index') }}" aria-label="Tìm và lọc đơn hàng">
        <div class="field order-filter__search"><label for="search">Mã đơn hàng</label><input id="search" name="search" value="{{ old('search', $filters['search'] ?? '') }}" maxlength="100" autocomplete="off" placeholder="Ví dụ: ORD-..." @error('search') aria-invalid="true" aria-describedby="search-error" @enderror>@error('search')<p id="search-error" class="field-error" role="alert">{{ $message }}</p>@enderror</div>
        <div class="field"><label for="status">Trạng thái đơn</label><select id="status" name="status"><option value="">Tất cả</option>@foreach($orderStatuses as $status)<option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ $status->label() }}</option>@endforeach</select></div>
        <div class="field"><label for="payment_status">Thanh toán</label><select id="payment_status" name="payment_status"><option value="">Tất cả</option>@foreach($paymentStatuses as $status)<option value="{{ $status->value }}" @selected(($filters['payment_status'] ?? '') === $status->value)>{{ $status->label() }}</option>@endforeach</select></div>
        <div class="field"><label for="payment_method">Phương thức</label><select id="payment_method" name="payment_method"><option value="">Tất cả</option>@foreach($paymentMethods as $method)<option value="{{ $method->value }}" @selected(($filters['payment_method'] ?? '') === $method->value)>{{ $method->label() }}</option>@endforeach</select></div>
        <div class="field"><label for="date_from">Từ ngày</label><input id="date_from" name="date_from" type="date" value="{{ old('date_from', $filters['date_from'] ?? '') }}" @error('date_from') aria-invalid="true" aria-describedby="date-from-error" @enderror>@error('date_from')<p id="date-from-error" class="field-error" role="alert">{{ $message }}</p>@enderror</div>
        <div class="field"><label for="date_to">Đến ngày</label><input id="date_to" name="date_to" type="date" value="{{ old('date_to', $filters['date_to'] ?? '') }}" @error('date_to') aria-invalid="true" aria-describedby="date-to-error" @enderror>@error('date_to')<p id="date-to-error" class="field-error" role="alert">{{ $message }}</p>@enderror</div>
        <div class="field"><label for="sort">Sắp xếp</label><select id="sort" name="sort"><option value="newest" @selected($filters['sort'] === 'newest')>Mới nhất</option><option value="oldest" @selected($filters['sort'] === 'oldest')>Cũ nhất</option><option value="total_asc" @selected($filters['sort'] === 'total_asc')>Tổng tiền tăng dần</option><option value="total_desc" @selected($filters['sort'] === 'total_desc')>Tổng tiền giảm dần</option></select></div>
        <div class="order-filter__actions"><button class="button" type="submit">Áp dụng</button><a class="text-link" href="{{ route('orders.index') }}">Xóa bộ lọc</a></div>
    </form>

    @if ($orders->isEmpty())
        <section class="empty-state order-empty" aria-labelledby="order-empty-title">
            <p class="eyebrow">{{ $hasActiveFilters ? 'Không có kết quả' : '0 đơn hàng' }}</p>
            <h2 id="order-empty-title">{{ $hasActiveFilters ? 'Không tìm thấy đơn hàng phù hợp' : 'Bạn chưa có đơn hàng nào' }}</h2>
            <p>{{ $hasActiveFilters ? 'Hãy điều chỉnh từ khóa hoặc bộ lọc để xem lại các đơn hàng khác.' : 'Khám phá catalog và thêm sản phẩm phù hợp vào giỏ hàng.' }}</p>
            <div class="action-group">@if($hasActiveFilters)<a class="button button--outline" href="{{ route('orders.index') }}">Xóa bộ lọc</a>@else<a class="button" href="{{ route('products.index') }}">Khám phá sản phẩm</a>@endif</div>
        </section>
    @else
        <section class="customer-order-list" aria-label="Danh sách đơn hàng">
            @foreach ($orders as $order)
                @php
                    $orderBadge = match($order->status) {
                        \App\Enums\OrderStatus::Delivered => 'status-badge--success',
                        \App\Enums\OrderStatus::Cancelled => 'status-badge--danger',
                        \App\Enums\OrderStatus::InTransit => 'status-badge--info',
                        default => 'status-badge--warning',
                    };
                @endphp
                <article class="customer-order-card">
                    <div class="customer-order-card__identity"><span class="section-label">Mã đơn</span><h2>{{ $order->order_code }}</h2><time datetime="{{ $order->created_at->toIso8601String() }}">{{ $order->created_at->timezone('Asia/Ho_Chi_Minh')->format('H:i · d/m/Y') }}</time></div>
                    <div class="customer-order-card__meta"><div><span>Trạng thái</span><span class="status-badge {{ $orderBadge }}">{{ $order->status->label() }}</span></div><div><span>Thanh toán</span><strong>{{ $order->payment_status->label() }}</strong></div><div><span>Tổng tiền</span><strong class="order-money">{{ number_format($order->total_vnd, 0, ',', '.') }} ₫</strong></div></div>
                    <a class="button button--quiet" href="{{ route('orders.show', $order->order_code) }}" aria-label="Xem chi tiết đơn {{ $order->order_code }}">Xem chi tiết</a>
                </article>
            @endforeach
        </section>
        <div class="pagination-wrap">{{ $orders->links() }}</div>
    @endif
</div>
@endsection
