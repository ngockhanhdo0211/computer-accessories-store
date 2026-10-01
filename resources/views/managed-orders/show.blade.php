@extends('layouts.workspace')
@section('title', 'Đơn '.$order->order_code)
@section('content')
@php
    $indexRoute = $routePrefix.'.orders.index';
    $transitionRoute = $routePrefix.'.orders.transition';
    $cancelRoute = $routePrefix.'.orders.cancel';
    $deliverRoute = $routePrefix.'.orders.deliver';
    $nextStatus = match($order->status) {
        \App\Enums\OrderStatus::Placed => \App\Enums\OrderStatus::AwaitingHandoff,
        \App\Enums\OrderStatus::AwaitingHandoff => \App\Enums\OrderStatus::InTransit,
        default => null,
    };
    $transitionLabel = match($nextStatus) {
        \App\Enums\OrderStatus::AwaitingHandoff => 'Xác nhận chờ chuyển phát',
        \App\Enums\OrderStatus::InTransit => 'Xác nhận đang trung chuyển',
        default => null,
    };
    $orderBadge = match($order->status) {
        \App\Enums\OrderStatus::Delivered => 'status-badge--success',
        \App\Enums\OrderStatus::Cancelled => 'status-badge--danger',
        \App\Enums\OrderStatus::InTransit => 'status-badge--info',
        default => 'status-badge--warning',
    };
@endphp
<div class="shell managed-order-page order-detail-page">
    <a class="text-link order-back-link" href="{{ route($indexRoute) }}">← Danh sách đơn hàng</a>
    <header class="order-detail-header"><div><p class="eyebrow">Chi tiết vận hành</p><h1>{{ $order->order_code }}</h1><p>Đặt lúc <time datetime="{{ $order->created_at->toIso8601String() }}">{{ $order->created_at->timezone('Asia/Ho_Chi_Minh')->format('H:i · d/m/Y') }}</time></p></div><div class="order-detail-header__status"><span class="status-badge {{ $orderBadge }}">{{ $order->status->label() }}</span><span>{{ $order->payment_status->label() }}</span></div></header>

    <div class="order-detail-layout">
        <div class="order-detail-main">
            <section class="order-panel" aria-labelledby="managed-items-title"><header class="order-panel__heading"><div><p class="section-label">Snapshot sản phẩm</p><h2 id="managed-items-title">Sản phẩm trong đơn</h2></div><span>{{ $order->items->sum('quantity') }} sản phẩm</span></header><div class="order-item-list">@foreach($order->items as $item)<article class="order-item-row"><div><h3>{{ $item->product_name }}</h3><p>SKU {{ $item->sku }} · {{ $item->quantity }} × {{ number_format($item->unit_price_vnd, 0, ',', '.') }} ₫</p><p>Tạm tính {{ number_format($item->line_subtotal_vnd, 0, ',', '.') }} ₫ · Giảm {{ number_format($item->discount_vnd, 0, ',', '.') }} ₫</p></div><strong>{{ number_format($item->line_total_vnd, 0, ',', '.') }} ₫</strong></article>@endforeach</div></section>

            <div class="order-party-grid">
                <section class="order-panel" aria-labelledby="customer-title"><header class="order-panel__heading"><div><p class="section-label">Tài khoản đặt hàng hiện tại</p><h2 id="customer-title">Khách hàng</h2></div></header><dl class="order-data-list order-data-list--single"><div><dt>Họ tên</dt><dd>{{ $order->customer->name }}</dd></div><div><dt>Email</dt><dd>{{ $order->customer->email }}</dd></div><div><dt>Điện thoại</dt><dd>{{ $order->customer->phone ?: 'Chưa cung cấp' }}</dd></div><div><dt>Trạng thái tài khoản</dt><dd>{{ $order->customer->status->label() }}</dd></div></dl></section>
                <section class="order-panel" aria-labelledby="managed-recipient-title"><header class="order-panel__heading"><div><p class="section-label">Snapshot giao hàng</p><h2 id="managed-recipient-title">Người nhận</h2></div></header><dl class="order-data-list order-data-list--single"><div><dt>Họ tên</dt><dd>{{ $order->recipient_name }}</dd></div><div><dt>Liên hệ</dt><dd>{{ $order->recipient_phone }}<br>{{ $order->recipient_email }}</dd></div><div><dt>Khu vực</dt><dd>{{ $order->recipient_region }}</dd></div><div><dt>Địa chỉ</dt><dd>{{ $order->recipient_address }}</dd></div></dl></section>
            </div>

            <section class="order-panel" aria-labelledby="managed-history-title"><header class="order-panel__heading"><div><p class="section-label">Dữ liệu thực tế</p><h2 id="managed-history-title">Lịch sử trạng thái</h2></div></header><ol class="order-timeline">@foreach($order->statusHistories as $history)<li><span class="order-timeline__marker" aria-hidden="true"></span><div><strong>{{ $history->from_status ? $history->from_status->label().' → ' : '' }}{{ $history->to_status->label() }}</strong><time datetime="{{ $history->created_at->toIso8601String() }}">{{ $history->created_at->timezone('Asia/Ho_Chi_Minh')->format('H:i · d/m/Y') }}</time><p>Thực hiện bởi: {{ $history->actor?->name ?? 'Hệ thống hoặc tài khoản không còn tồn tại' }}</p>@if($history->reason)<p>Lý do: {{ $history->reason }}</p>@endif</div></li>@endforeach</ol></section>
        </div>

        <aside class="order-summary" aria-labelledby="managed-summary-title"><header><p class="section-label">Giá trị đã lưu</p><h2 id="managed-summary-title">Thanh toán</h2></header><dl class="checkout-totals"><div><dt>Phương thức</dt><dd>{{ $order->payment_method->label() }}</dd></div><div><dt>Trạng thái</dt><dd>{{ $order->payment_status->label() }}</dd></div><div><dt>Tạm tính</dt><dd>{{ number_format($order->items_subtotal_vnd, 0, ',', '.') }} ₫</dd></div><div><dt>Giảm sản phẩm</dt><dd>− {{ number_format($order->item_discount_vnd, 0, ',', '.') }} ₫</dd></div><div><dt>Phí vận chuyển</dt><dd>{{ number_format($order->shipping_fee_vnd, 0, ',', '.') }} ₫</dd></div><div><dt>Giảm vận chuyển</dt><dd>− {{ number_format($order->shipping_discount_vnd, 0, ',', '.') }} ₫</dd></div><div><dt>Tổng ưu đãi</dt><dd>− {{ number_format($order->total_discount_vnd, 0, ',', '.') }} ₫</dd></div>@if($order->coupon_snapshot_json)<div><dt>Coupon snapshot</dt><dd>{{ $order->coupon_snapshot_json['code'] }}</dd></div>@endif<div class="checkout-totals__grand"><dt>Tổng cộng</dt><dd>{{ number_format($order->total_vnd, 0, ',', '.') }} ₫</dd></div></dl>
            @if($order->paymentAttempt)<div class="payment-attempt-summary"><p class="section-label">Payment Attempt</p><dl><div><dt>Tham chiếu</dt><dd>{{ $order->paymentAttempt->gateway_reference }}</dd></div><div><dt>Trạng thái</dt><dd>{{ $order->paymentAttempt->status->label() }}</dd></div><div><dt>Số tiền</dt><dd>{{ number_format($order->paymentAttempt->amount_vnd, 0, ',', '.') }} ₫</dd></div><div><dt>Xác minh</dt><dd>{{ $order->paymentAttempt->verified_at?->timezone('Asia/Ho_Chi_Minh')->format('H:i d/m/Y') ?? 'Chưa xác minh' }}</dd></div></dl></div>@endif

            @if($nextStatus)
                <section class="order-transition" aria-labelledby="order-transition-title">
                    <p class="section-label">Tiến trình vận chuyển</p>
                    <h2 id="order-transition-title">Bước tiếp theo</h2>
                    <p>Chuyển từ <strong>{{ $order->status->label() }}</strong> sang <strong>{{ $nextStatus->label() }}</strong>.</p>
                    @if($errors->hasAny(['authorization', 'target_status', 'event_key', 'reason']))
                        <div class="alert alert--error order-transition__error" role="alert">Không thể cập nhật tiến trình. Hãy kiểm tra thông tin bên dưới.</div>
                    @endif
                    <form method="POST" action="{{ route($transitionRoute, $order->order_code) }}">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="target_status" value="{{ $nextStatus->value }}">
                        <input type="hidden" name="event_key" value="{{ $errors->has('event_key') || !$errors->hasAny(['authorization', 'target_status', 'event_key', 'reason']) ? (string) \Illuminate\Support\Str::uuid() : old('event_key', (string) \Illuminate\Support\Str::uuid()) }}">
                        @error('authorization')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                        @error('target_status')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                        @error('event_key')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                        <div class="field">
                            <label for="reason">Ghi chú vận hành <span>(không bắt buộc)</span></label>
                            <textarea id="reason" name="reason" rows="3" maxlength="500" aria-describedby="reason-message" @error('reason') aria-invalid="true" @enderror>{{ $errors->hasAny(['authorization', 'target_status', 'event_key', 'reason']) ? old('reason') : '' }}</textarea>
                            <p id="reason-message" class="field-message @error('reason') field-error @enderror">@error('reason'){{ $message }}@else Ghi lại thông tin bàn giao hữu ích cho lịch sử đơn. @enderror</p>
                        </div>
                        <button class="button" type="submit">{{ $transitionLabel }}</button>
                    </form>
                </section>
            @endif

            @if($canCancelCod || $canDeliverCod)
                <section class="order-transition order-terminal-actions" aria-labelledby="order-terminal-title">
                    <p class="section-label">Kết thúc vòng đời COD</p>
                    <h2 id="order-terminal-title">Thao tác đơn hàng</h2>

                    @if($canDeliverCod)
                        <form method="POST" action="{{ route($deliverRoute, $order->order_code) }}" data-submit-once data-confirm-action="Xác nhận đơn COD đã được giao thành công?">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="event_key" value="{{ $errors->deliverOrder->has('event_key') || !$errors->deliverOrder->any() ? (string) \Illuminate\Support\Str::uuid() : old('event_key', (string) \Illuminate\Support\Str::uuid()) }}">
                            @if($errors->deliverOrder->any())
                                <div class="alert alert--error order-transition__error" role="alert">Không thể xác nhận giao đơn. Hãy kiểm tra thông tin bên dưới.</div>
                            @endif
                            @foreach(['authorization', 'order', 'inventory', 'event_key', 'request'] as $field)
                                @error($field, 'deliverOrder')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                            @endforeach
                            <div class="field">
                                <label for="delivery-reason">Ghi chú giao hàng <span>(không bắt buộc)</span></label>
                                <textarea id="delivery-reason" name="reason" rows="3" maxlength="500" aria-describedby="delivery-reason-message" @error('reason', 'deliverOrder') aria-invalid="true" @enderror>{{ $errors->deliverOrder->any() ? old('reason') : '' }}</textarea>
                                <p id="delivery-reason-message" class="field-message @error('reason', 'deliverOrder') field-error @enderror">@error('reason', 'deliverOrder'){{ $message }}@else Chỉ xác nhận khi đơn đã được giao thành công cho người nhận. @enderror</p>
                            </div>
                            <button class="button" type="submit">Xác nhận đã giao</button>
                        </form>
                    @endif

                    @if($canCancelCod)
                        <div class="order-terminal-actions__divider" aria-hidden="true"></div>
                        @if(!$inspectionReady)
                            <div class="empty-state empty-state--compact">
                                <h3>Chưa thể hủy đơn</h3>
                                <p>Phải tiếp nhận và hoàn tất phân loại toàn bộ Order Item trước khi hoàn kho.</p>
                            </div>
                        @else
                            <form method="POST" action="{{ route($cancelRoute, $order->order_code) }}" data-submit-once data-confirm-action="Hủy đơn COD và hoàn kho theo kết quả kiểm tra?">
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="event_key" value="{{ $errors->cancelOrder->has('event_key') || !$errors->cancelOrder->any() ? (string) \Illuminate\Support\Str::uuid() : old('event_key', (string) \Illuminate\Support\Str::uuid()) }}">
                                @if($errors->cancelOrder->any())
                                    <div class="alert alert--error order-transition__error" role="alert">Không thể hủy đơn. Hãy kiểm tra thông tin bên dưới.</div>
                                @endif
                                @foreach(['authorization', 'order', 'inspection', 'inventory', 'coupon_usage', 'event_key', 'request'] as $field)
                                    @error($field, 'cancelOrder')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                                @endforeach
                                <div class="field">
                                    <label for="cancel-reason">Lý do hủy</label>
                                    <textarea id="cancel-reason" name="reason" rows="3" maxlength="500" required aria-describedby="cancel-reason-message" @error('reason', 'cancelOrder') aria-invalid="true" @enderror>{{ $errors->cancelOrder->any() ? old('reason') : '' }}</textarea>
                                    <p id="cancel-reason-message" class="field-message @error('reason', 'cancelOrder') field-error @enderror">@error('reason', 'cancelOrder'){{ $message }}@else Lý do được lưu vào lịch sử trạng thái và audit. @enderror</p>
                                </div>
                                <button class="button button--danger" type="submit">Hủy đơn và hoàn kho</button>
                            </form>
                        @endif
                    @endif
                </section>
            @endif
        </aside>
    </div>
</div>
@endsection
