@extends('layouts.workspace')
@section('title', 'Đơn hàng')
@section('content')
@php
    $indexRoute = $routePrefix.'.orders.index';
    $showRoute = $routePrefix.'.orders.show';
    $hasActiveFilters = collect(['search','status','payment_status','payment_method','date_from','date_to'])->contains(fn ($key) => filled($filters[$key] ?? null)) || $filters['sort'] !== 'newest';
@endphp
<div class="shell managed-order-page">
    <header class="page-heading managed-order-heading"><div><p class="eyebrow">Bán hàng</p><h1>Đơn hàng</h1><p>Tra cứu theo khách hàng, thanh toán hoặc trạng thái để tiếp tục xử lý đơn.</p></div><p class="order-count"><strong>{{ $orders->total() }}</strong> đơn hàng</p></header>

    @if ($errors->any())
        <div class="alert alert--error order-filter-error" role="alert">Không thể áp dụng bộ lọc. Hãy kiểm tra các trường được đánh dấu bên dưới.</div>
    @endif
    <form class="order-filter order-filter--managed" method="GET" action="{{ route($indexRoute) }}" aria-label="Tìm và lọc đơn hàng quản trị">
        <div class="field order-filter__search"><label for="search">Tìm kiếm</label><input id="search" name="search" value="{{ old('search', $filters['search'] ?? '') }}" maxlength="100" autocomplete="off" placeholder="Mã đơn, khách/người nhận, email, SĐT, địa chỉ" @error('search') aria-invalid="true" aria-describedby="search-error" @enderror>@error('search')<p id="search-error" class="field-error" role="alert">{{ $message }}</p>@enderror</div>
        <div class="field"><label for="status">Trạng thái đơn</label><select id="status" name="status"><option value="">Tất cả</option>@foreach($orderStatuses as $status)<option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ $status->label() }}</option>@endforeach</select></div>
        <div class="field"><label for="payment_status">Thanh toán</label><select id="payment_status" name="payment_status"><option value="">Tất cả</option>@foreach($paymentStatuses as $status)<option value="{{ $status->value }}" @selected(($filters['payment_status'] ?? '') === $status->value)>{{ $status->label() }}</option>@endforeach</select></div>
        <div class="field"><label for="payment_method">Phương thức</label><select id="payment_method" name="payment_method"><option value="">Tất cả</option>@foreach($paymentMethods as $method)<option value="{{ $method->value }}" @selected(($filters['payment_method'] ?? '') === $method->value)>{{ $method->label() }}</option>@endforeach</select></div>
        <div class="field"><label for="date_from">Từ ngày</label><input id="date_from" name="date_from" type="date" value="{{ old('date_from', $filters['date_from'] ?? '') }}" @error('date_from') aria-invalid="true" aria-describedby="date-from-error" @enderror>@error('date_from')<p id="date-from-error" class="field-error" role="alert">{{ $message }}</p>@enderror</div>
        <div class="field"><label for="date_to">Đến ngày</label><input id="date_to" name="date_to" type="date" value="{{ old('date_to', $filters['date_to'] ?? '') }}" @error('date_to') aria-invalid="true" aria-describedby="date-to-error" @enderror>@error('date_to')<p id="date-to-error" class="field-error" role="alert">{{ $message }}</p>@enderror</div>
        <div class="field"><label for="sort">Sắp xếp</label><select id="sort" name="sort"><option value="newest" @selected($filters['sort'] === 'newest')>Mới nhất</option><option value="oldest" @selected($filters['sort'] === 'oldest')>Cũ nhất</option><option value="total_asc" @selected($filters['sort'] === 'total_asc')>Tổng tiền tăng dần</option><option value="total_desc" @selected($filters['sort'] === 'total_desc')>Tổng tiền giảm dần</option></select></div>
        <div class="order-filter__actions"><button class="button" type="submit">Áp dụng</button><a class="text-link" href="{{ route($indexRoute) }}">Xóa bộ lọc</a></div>
    </form>

    @if($orders->isEmpty())
        <section class="empty-state order-empty"><p class="eyebrow">{{ $hasActiveFilters ? 'Không có kết quả' : '0 đơn hàng' }}</p><h2>{{ $hasActiveFilters ? 'Không tìm thấy đơn hàng phù hợp' : 'Chưa có đơn hàng' }}</h2><p>{{ $hasActiveFilters ? 'Điều chỉnh điều kiện tìm kiếm hoặc xóa bộ lọc.' : 'Danh sách sẽ xuất hiện khi khách hàng đặt đơn thành công.' }}</p>@if($hasActiveFilters)<div class="action-group"><a class="button button--outline" href="{{ route($indexRoute) }}">Xóa bộ lọc</a></div>@endif</section>
    @else
        <div class="managed-order-table-wrap"><table class="managed-order-table"><thead><tr><th>Mã đơn</th><th>Khách hàng / người nhận</th><th>Ngày đặt</th><th>Tổng tiền</th><th>Phương thức</th><th>Thanh toán</th><th>Trạng thái</th><th>Thao tác</th></tr></thead><tbody>
        @foreach($orders as $order)
            @php($orderBadge = match($order->status) { \App\Enums\OrderStatus::Delivered => 'status-badge--success', \App\Enums\OrderStatus::Cancelled => 'status-badge--danger', \App\Enums\OrderStatus::InTransit => 'status-badge--info', default => 'status-badge--warning' })
            <tr><td data-label="Mã đơn"><strong class="order-code">{{ $order->order_code }}</strong></td><td data-label="Khách hàng / người nhận"><strong>{{ $order->customer->name }}</strong><span>{{ $order->customer->email }}</span><small>Nhận: {{ $order->recipient_name }} · {{ $order->recipient_phone }}</small></td><td data-label="Ngày đặt"><time datetime="{{ $order->created_at->toIso8601String() }}">{{ $order->created_at->timezone('Asia/Ho_Chi_Minh')->format('H:i d/m/Y') }}</time></td><td data-label="Tổng tiền"><strong class="order-money">{{ number_format($order->total_vnd, 0, ',', '.') }} ₫</strong></td><td data-label="Phương thức">{{ $order->payment_method->label() }}</td><td data-label="Thanh toán">{{ $order->payment_status->label() }}</td><td data-label="Trạng thái"><span class="status-badge {{ $orderBadge }}">{{ $order->status->label() }}</span></td><td data-label="Thao tác"><a class="text-link managed-order-table__action" href="{{ route($showRoute, $order->order_code) }}">Xem chi tiết</a></td></tr>
        @endforeach
        </tbody></table></div><div class="pagination-wrap">{{ $orders->links() }}</div>
    @endif
</div>
@endsection
