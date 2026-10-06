@extends('layouts.workspace')
@section('title', 'Kiểm tra hàng hoàn · '.$order->order_code)
@section('content')
@php
    $completedCount = $order->items->filter(fn ($item) => $item->returnInspection?->isCompleted())->count();
@endphp
<div class="shell return-inspection-page">
    <a class="text-link order-back-link" href="{{ route($routePrefix.'.orders.show', $order->order_code) }}">← Chi tiết đơn {{ $order->order_code }}</a>
    <header class="page-heading"><div><p class="eyebrow">Vận hành đơn hàng</p><h1>Kiểm tra hàng hoàn</h1><p>Tiếp nhận và phân loại từng sản phẩm trước khi hủy đơn và hoàn kho.</p></div><span class="status-badge">{{ $completedCount }}/{{ $order->items->count() }} đã hoàn tất</span></header>

    <div class="inspection-progress" role="status" aria-label="Tiến độ kiểm tra hàng hoàn">
        <div><strong>{{ $completedCount }} / {{ $order->items->count() }}</strong><span>Order Item đã phân loại</span></div>
        <progress value="{{ $completedCount }}" max="{{ $order->items->count() }}">{{ $completedCount }}/{{ $order->items->count() }}</progress>
    </div>

    <div class="inspection-list">
        @foreach($order->items as $item)
            @php
                $inspection = $item->returnInspection;
                $receiveBag = $errors->getBag('receive-'.$item->id);
                $completeBag = $errors->getBag('complete-'.$item->id);
                $state = $inspection?->isCompleted() ? 'completed' : ($inspection ? 'pending' : 'new');
            @endphp
            <article class="inspection-card inspection-card--{{ $state }}" aria-labelledby="inspection-item-{{ $item->id }}">
                <header class="inspection-card__header">
                    <div><p class="section-label">SKU {{ $item->sku }}</p><h2 id="inspection-item-{{ $item->id }}">{{ $item->product_name }}</h2><p>Số lượng trong đơn: <strong>{{ $item->quantity }}</strong></p></div>
                    <span class="status-badge {{ $state === 'completed' ? 'status-badge--success' : ($state === 'pending' ? 'status-badge--warning' : '') }}">{{ $state === 'completed' ? 'Đã hoàn tất' : ($state === 'pending' ? 'Chờ phân loại' : 'Chưa tiếp nhận') }}</span>
                </header>

                @if(!$inspection)
                    <form method="POST" action="{{ route($routePrefix.'.orders.return-inspections.receive', [$order->order_code, $item->id]) }}" class="inspection-form">
                        @csrf
                        <input type="hidden" name="event_key" value="{{ $receiveBag->any() ? old('event_key') : (string) \Illuminate\Support\Str::uuid() }}">
                        @foreach(['authorization', 'order_item', 'event_key', 'audit', 'request'] as $field)
                            @if($receiveBag->has($field))
                                <p class="field-error" role="alert">{{ $receiveBag->first($field) }}</p>
                            @endif
                        @endforeach
                        <div class="field"><label for="receive-note-{{ $item->id }}">Ghi chú tiếp nhận <span>(không bắt buộc)</span></label><textarea id="receive-note-{{ $item->id }}" name="note" maxlength="500" rows="2" @if($receiveBag->has('note')) aria-invalid="true" aria-describedby="receive-note-error-{{ $item->id }}" @endif>{{ $receiveBag->any() ? old('note') : '' }}</textarea>@if($receiveBag->has('note'))<p id="receive-note-error-{{ $item->id }}" class="field-error" role="alert">{{ $receiveBag->first('note') }}</p>@endif</div>
                        <button class="button" type="submit">Tiếp nhận hàng</button>
                    </form>
                @elseif(!$inspection->isCompleted())
                    <dl class="inspection-evidence"><div><dt>Người tiếp nhận</dt><dd>{{ $inspection->receiver->name }}</dd></div><div><dt>Thời điểm</dt><dd>{{ $inspection->received_at->timezone('Asia/Ho_Chi_Minh')->format('H:i · d/m/Y') }}</dd></div>@if($inspection->note)<div class="inspection-evidence__wide"><dt>Ghi chú tiếp nhận</dt><dd>{{ $inspection->note }}</dd></div>@endif</dl>
                    <form method="POST" action="{{ route($routePrefix.'.orders.return-inspections.complete', [$order->order_code, $item->id]) }}" class="inspection-form">
                        @csrf @method('PATCH')
                        <input type="hidden" name="event_key" value="{{ $completeBag->any() ? old('event_key') : (string) \Illuminate\Support\Str::uuid() }}">
                        @foreach(['authorization', 'order_item', 'quantity', 'inspected_at', 'event_key', 'audit', 'request'] as $field)
                            @if($completeBag->has($field))
                                <p class="field-error" role="alert">{{ $completeBag->first($field) }}</p>
                            @endif
                        @endforeach
                        <div class="inspection-quantity-grid">
                            <div class="field"><label for="sellable-{{ $item->id }}">Có thể bán lại</label><input id="sellable-{{ $item->id }}" name="sellable_quantity" type="number" min="0" max="{{ $item->quantity }}" step="1" inputmode="numeric" required value="{{ $completeBag->any() ? old('sellable_quantity') : $item->quantity }}" @if($completeBag->has('sellable_quantity')) aria-invalid="true" aria-describedby="sellable-error-{{ $item->id }}" @endif>@if($completeBag->has('sellable_quantity'))<p id="sellable-error-{{ $item->id }}" class="field-error" role="alert">{{ $completeBag->first('sellable_quantity') }}</p>@endif</div>
                            <div class="field"><label for="damaged-{{ $item->id }}">Hàng hỏng</label><input id="damaged-{{ $item->id }}" name="damaged_quantity" type="number" min="0" max="{{ $item->quantity }}" step="1" inputmode="numeric" required value="{{ $completeBag->any() ? old('damaged_quantity') : 0 }}" @if($completeBag->has('damaged_quantity')) aria-invalid="true" aria-describedby="damaged-error-{{ $item->id }}" @endif>@if($completeBag->has('damaged_quantity'))<p id="damaged-error-{{ $item->id }}" class="field-error" role="alert">{{ $completeBag->first('damaged_quantity') }}</p>@endif</div>
                        </div>
                        <p class="field-message">Tổng hai nhóm phải bằng {{ $item->quantity }} sản phẩm.</p>
                        <div class="field"><label for="complete-note-{{ $item->id }}">Ghi chú phân loại <span>(không bắt buộc)</span></label><textarea id="complete-note-{{ $item->id }}" name="note" maxlength="500" rows="2" @if($completeBag->has('note')) aria-invalid="true" aria-describedby="complete-note-error-{{ $item->id }}" @endif>{{ $completeBag->any() ? old('note') : $inspection->note }}</textarea>@if($completeBag->has('note'))<p id="complete-note-error-{{ $item->id }}" class="field-error" role="alert">{{ $completeBag->first('note') }}</p>@endif</div>
                        <button class="button" type="submit">Hoàn tất phân loại</button>
                    </form>
                @else
                    <dl class="inspection-evidence"><div><dt>Có thể bán lại</dt><dd><strong>{{ $inspection->sellable_quantity }}</strong></dd></div><div><dt>Hàng hỏng</dt><dd><strong>{{ $inspection->damaged_quantity }}</strong></dd></div><div><dt>Người tiếp nhận</dt><dd>{{ $inspection->receiver->name }}</dd></div><div><dt>Người kiểm tra</dt><dd>{{ $inspection->inspector->name }}</dd></div><div><dt>Tiếp nhận lúc</dt><dd>{{ $inspection->received_at->timezone('Asia/Ho_Chi_Minh')->format('H:i · d/m/Y') }}</dd></div><div><dt>Hoàn tất lúc</dt><dd>{{ $inspection->inspected_at->timezone('Asia/Ho_Chi_Minh')->format('H:i · d/m/Y') }}</dd></div>@if($inspection->note)<div class="inspection-evidence__wide"><dt>Ghi chú</dt><dd>{{ $inspection->note }}</dd></div>@endif</dl>
                @endif
            </article>
        @endforeach
    </div>
</div>
@endsection
