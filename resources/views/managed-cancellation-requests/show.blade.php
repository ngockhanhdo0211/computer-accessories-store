@extends('layouts.workspace')
@section('title', 'Yêu cầu hủy '.$cancellationRequest->order->order_code)
@section('content')
@php
    $requestBadge = match($cancellationRequest->status) {
        \App\Enums\OrderCancellationRequestStatus::Approved => 'status-badge--success',
        \App\Enums\OrderCancellationRequestStatus::Rejected => 'status-badge--danger',
        default => 'status-badge--warning',
    };
@endphp
<div class="shell admin-page managed-order-page">
    <a class="text-link" href="{{ route($routePrefix.'.order-cancellation-requests.index') }}">← Danh sách yêu cầu</a>
    <header class="order-detail-header"><div><p class="eyebrow">Yêu cầu hủy đơn</p><h1>{{ $cancellationRequest->order->order_code }}</h1><p>{{ $cancellationRequest->customer->name }} · {{ $cancellationRequest->order->payment_method->label() }}</p></div><span class="status-badge {{ $requestBadge }}">{{ $cancellationRequest->status->label() }}</span></header>
    <div class="order-detail-layout"><div class="order-detail-main"><section class="order-panel"><h2>Lý do khách hàng cung cấp</h2><p>{{ $cancellationRequest->reason }}</p></section><section class="order-panel"><h2>Thông tin đơn hàng</h2><dl class="order-summary-list"><div><dt>Trạng thái đơn</dt><dd>{{ $cancellationRequest->order->status->label() }}</dd></div><div><dt>Thanh toán</dt><dd>{{ $cancellationRequest->order->payment_status->label() }}</dd></div><div><dt>Tổng tiền</dt><dd>{{ number_format($cancellationRequest->order->total_vnd, 0, ',', '.') }} ₫</dd></div><div><dt>Sản phẩm</dt><dd>{{ $cancellationRequest->order->items->sum('quantity') }}</dd></div>@if($cancellationRequest->order->refund)<div><dt>Hoàn tiền</dt><dd>{{ $cancellationRequest->order->refund->status->value }}</dd></div>@endif</dl></section></div>
    <aside class="order-detail-sidebar">
        @if($cancellationRequest->status === \App\Enums\OrderCancellationRequestStatus::Pending)
            <section class="order-transition">
                <h2>Quyết định</h2>
                @if($isDrifted)
                    <div class="alert alert--warning" role="status">Đơn hàng đã rời trạng thái Đã đặt. Yêu cầu này không thể được chấp thuận; hãy ghi rõ lý do và đóng yêu cầu bằng quyết định từ chối.</div>
                @elseif(! $canApprove)
                    <div class="alert alert--warning" role="status">Thông tin thanh toán không còn khớp điều kiện chấp thuận. Chỉ có thể từ chối yêu cầu sau khi đối soát.</div>
                @endif
                @if($canApprove)
                    @if($cancellationRequest->order->payment_method === \App\Enums\PaymentMethod::VnPay)
                        <div class="alert alert--warning">Chấp thuận sẽ hủy đơn, hoàn kho và tạo yêu cầu hoàn tiền đang chờ; VNPay chưa được gọi ở bước này.</div>
                    @endif
                    @php
                        $bag = $errors->approveCancellation;
                        $noteId = 'note-approve';
                        $errorId = $noteId.'-error';
                    @endphp
                    <form method="POST" action="{{ route($routePrefix.'.order-cancellation-requests.approve', $cancellationRequest) }}" data-submit-once data-confirm-action="Xác nhận chấp thuận yêu cầu hủy này?">
                        <input type="hidden" name="event_key" value="{{ $bag->any() ? old('event_key') : (string) \Illuminate\Support\Str::uuid() }}">
                        @csrf
                        @method('PATCH')
                        <div class="field">
                            <label for="{{ $noteId }}">Ghi chú (không bắt buộc)</label>
                            <textarea id="{{ $noteId }}" name="note" maxlength="500" @if($bag->has('note')) aria-invalid="true" aria-describedby="{{ $errorId }}" @endif>{{ $bag->any() ? old('note') : '' }}</textarea>
                            @if($bag->has('note'))<p class="field-error" id="{{ $errorId }}" role="alert">{{ $bag->first('note') }}</p>@endif
                        </div>
                        @foreach(['request','order','payment','inventory','coupon_usage','refund','event_key','authorization','audit'] as $field)
                            @if($bag->has($field))<p class="field-error order-transition__error" role="alert">{{ $bag->first($field) }}</p>@endif
                        @endforeach
                        <button class="button" type="submit">Chấp thuận hủy</button>
                    </form>
                @endif
                @php
                    $bag = $errors->rejectCancellation;
                    $noteId = 'note-reject';
                    $errorId = $noteId.'-error';
                @endphp
                <form method="POST" action="{{ route($routePrefix.'.order-cancellation-requests.reject', $cancellationRequest) }}" data-submit-once data-confirm-action="Xác nhận từ chối và đóng yêu cầu này?">
                    <input type="hidden" name="event_key" value="{{ $bag->any() ? old('event_key') : (string) \Illuminate\Support\Str::uuid() }}">
                    @csrf
                    @method('PATCH')
                    <div class="field">
                        <label for="{{ $noteId }}">Lý do từ chối</label>
                        <textarea id="{{ $noteId }}" name="note" maxlength="500" required @if($bag->has('note')) aria-invalid="true" aria-describedby="{{ $errorId }}" @endif>{{ $bag->any() ? old('note') : '' }}</textarea>
                        @if($bag->has('note'))<p class="field-error" id="{{ $errorId }}" role="alert">{{ $bag->first('note') }}</p>@endif
                    </div>
                    @foreach(['request','order','payment','inventory','coupon_usage','refund','event_key','authorization','audit'] as $field)
                        @if($bag->has($field))<p class="field-error order-transition__error" role="alert">{{ $bag->first($field) }}</p>@endif
                    @endforeach
                    <button class="button button--quiet" type="submit">{{ $isDrifted ? 'Đóng yêu cầu bằng từ chối' : 'Từ chối' }}</button>
                </form>
            </section>
        @else
            <section class="order-transition"><h2>Đã xử lý</h2><p>{{ $cancellationRequest->review_note ?? 'Không có ghi chú.' }}</p><p>{{ $cancellationRequest->reviewed_at?->timezone('Asia/Ho_Chi_Minh')->format('H:i · d/m/Y') }}</p></section>
        @endif
    </aside></div>
</div>
@endsection
