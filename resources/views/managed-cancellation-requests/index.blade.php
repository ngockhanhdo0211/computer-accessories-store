@extends('layouts.workspace')
@section('title', 'Yêu cầu hủy đơn')
@section('content')
<div class="shell admin-page managed-order-page">
    <header class="page-heading managed-order-heading"><div><p class="eyebrow">Vận hành đơn hàng</p><h1>Yêu cầu hủy đơn</h1><p>Tiếp nhận và quyết định các yêu cầu do Customer gửi khi đơn còn ở trạng thái Đã đặt.</p></div></header>
    @if($requests->isEmpty())
        <section class="empty-state"><h2>Chưa có yêu cầu</h2><p>Các yêu cầu mới sẽ xuất hiện tại đây.</p></section>
    @else
        <div class="managed-order-table-wrap"><table class="managed-order-table"><thead><tr><th>Đơn hàng</th><th>Customer</th><th>Thanh toán</th><th>Lý do</th><th>Trạng thái</th><th>Gửi lúc</th><th>Thao tác</th></tr></thead><tbody>
        @foreach($requests as $item)<tr><td><strong>{{ $item->order->order_code }}</strong></td><td>{{ $item->customer->name }}</td><td>{{ $item->order->payment_method->label() }}</td><td>{{ \Illuminate\Support\Str::limit($item->reason, 70) }}</td><td><span class="status-badge status-badge--{{ $item->status === \App\Enums\OrderCancellationRequestStatus::Approved ? 'success' : ($item->status === \App\Enums\OrderCancellationRequestStatus::Rejected ? 'danger' : 'warning') }}">{{ $item->status->label() }}</span></td><td>{{ $item->created_at->timezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i') }}</td><td><a class="text-link" href="{{ route($routePrefix.'.order-cancellation-requests.show', $item) }}">Xem</a></td></tr>@endforeach
        </tbody></table></div>{{ $requests->links() }}
    @endif
</div>
@endsection
