@extends('layouts.workspace')

@php($isDamaged = $mode === 'damaged')
@section('title', $isDamaged ? 'Ghi nhận hàng hỏng' : 'Nhập kho')

@section('content')
<div class="shell inventory-page inventory-form-layout">
    <header class="page-intro"><p class="eyebrow">Tồn kho / {{ $product->sku }}</p><h1>{{ $isDamaged ? 'Ghi nhận hàng hỏng' : 'Nhập kho' }}</h1><p>{{ $product->name }}</p></header>
    <section class="form-panel">
        <dl class="inventory-current"><div><dt>Tồn bán được</dt><dd>{{ $product->sellable_quantity }}</dd></div><div><dt>Hàng hỏng</dt><dd>{{ $product->damaged_quantity }}</dd></div><div><dt>Đã giao</dt><dd>{{ $product->sold_quantity }}</dd></div></dl>
        <form method="POST" action="{{ $isDamaged ? route('inventory.damaged', $product) : route('inventory.import', $product) }}" data-submit-once @if($isDamaged) data-confirm-action="Chuyển số lượng này từ tồn bán được sang hàng hỏng?" @endif>
            @csrf
            <input type="hidden" name="request_key" value="{{ old('request_key', (string) Illuminate\Support\Str::uuid()) }}">
            <div class="field"><label for="quantity">Số lượng</label><input id="quantity" name="quantity" type="number" min="1" max="2147483647" step="1" inputmode="numeric" value="{{ old('quantity') }}" required aria-describedby="quantity-help quantity-error"><small id="quantity-help">{{ $isDamaged ? 'Không được vượt tồn bán được.' : 'Sẽ cộng vào tồn bán được.' }}</small>@error('quantity')<p id="quantity-error" class="field-error">{{ $message }}</p>@enderror</div>
            <div class="field"><label for="reason">Lý do</label><textarea id="reason" name="reason" maxlength="255" required>{{ old('reason') }}</textarea>@error('reason')<p class="field-error">{{ $message }}</p>@enderror</div>
            <div class="form-actions"><button class="button" type="submit">{{ $isDamaged ? 'Xác nhận hàng hỏng' : 'Xác nhận nhập kho' }}</button><a class="text-link" href="{{ route('inventory.history', $product) }}">Hủy</a></div>
        </form>
    </section>
</div>
@endsection
