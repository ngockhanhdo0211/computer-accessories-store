@extends('layouts.workspace')
@section('title', 'Yêu cầu hủy đơn')
@section('content')
<div class="shell admin-page managed-order-page">
    <header class="page-heading managed-order-heading"><div><p class="eyebrow">Bán hàng / Đơn hàng</p><h1>Yêu cầu hủy đơn</h1><p>Xem lý do, kiểm tra trạng thái đơn và quyết định từng yêu cầu của khách hàng.</p></div></header>
    @if($requests->isEmpty())
        <section class="empty-state"><h2>Chưa có yêu cầu</h2><p>Các yêu cầu mới sẽ xuất hiện tại đây.</p></section>
    @else
        <div class="managed-order-table-wrap"><table class="managed-order-table"><thead><tr><th>Đơn hàng</th><th>Khách hàng</th><th>Thanh toán</th><th>Lý do</th><th>Trạng thái</th><th>Gửi lúc</th><th>Thao tác</th></tr></thead><tbody>
        @foreach($requests as $item)<tr><td data-label="Đơn hàng"><strong class="order-code">{{ $item->order->order_code }}</strong></td><td data-label="Khách hàng">{{ $item->customer->name }}</td><td data-label="Thanh toán">{{ $item->order->payment_method->label() }}</td><td data-label="Lý do">{{ \Illuminate\Support\Str::limit($item->reason, 70) }}</td><td data-label="Trạng thái"><span class="status-badge status-badge--{{ $item->status === \App\Enums\OrderCancellationRequestStatus::Approved ? 'success' : ($item->status === \App\Enums\OrderCancellationRequestStatus::Rejected ? 'danger' : 'warning') }}">{{ $item->status->label() }}</span></td><td data-label="Gửi lúc">{{ $item->created_at->timezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i') }}</td><td data-label="Thao tác"><a class="text-link" href="{{ route($routePrefix.'.order-cancellation-requests.show', $item) }}">Xem chi tiết</a></td></tr>@endforeach
        </tbody></table></div>{{ $requests->links() }}
    @endif
</div>
@endsection
