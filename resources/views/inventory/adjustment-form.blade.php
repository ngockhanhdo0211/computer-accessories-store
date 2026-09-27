@extends('layouts.workspace')

@section('title', $direct ? 'Điều chỉnh kho trực tiếp' : 'Tạo đề nghị điều chỉnh')

@section('content')
<div class="shell inventory-page inventory-form-layout">
    <header class="page-intro"><p class="eyebrow">Tồn kho / {{ $product->sku }}</p><h1>{{ $direct ? 'Điều chỉnh trực tiếp' : 'Đề nghị điều chỉnh' }}</h1><p>{{ $product->name }} · bán được {{ $product->sellable_quantity }}, hỏng {{ $product->damaged_quantity }}.</p></header>
    <section class="form-panel">
        <p class="form-note">Dùng số dương để tăng, số âm để giảm. {{ $direct ? 'Thao tác Admin được duyệt ngay và có audit log.' : 'Đề nghị chờ Admin duyệt, chưa thay đổi tồn kho.' }}</p>
        <form method="POST" action="{{ $direct ? route('inventory.adjustments.direct', $product) : route('inventory.adjustments.store', $product) }}" data-submit-once @if($direct) data-confirm-action="Áp dụng điều chỉnh kho trực tiếp và ghi audit log?" @endif>
            @csrf
            <input type="hidden" name="request_key" value="{{ old('request_key', (string) Illuminate\Support\Str::uuid()) }}">
            <div class="form-grid"><div class="field"><label for="sellable_delta">Chênh lệch tồn bán được</label><input id="sellable_delta" name="sellable_delta" type="number" step="1" value="{{ old('sellable_delta', 0) }}" required>@error('sellable_delta')<p class="field-error">{{ $message }}</p>@enderror</div><div class="field"><label for="damaged_delta">Chênh lệch hàng hỏng</label><input id="damaged_delta" name="damaged_delta" type="number" step="1" value="{{ old('damaged_delta', 0) }}" required>@error('damaged_delta')<p class="field-error">{{ $message }}</p>@enderror</div></div>
            <div class="field"><label for="reason">Lý do bắt buộc</label><textarea id="reason" name="reason" maxlength="500" required>{{ old('reason') }}</textarea>@error('reason')<p class="field-error">{{ $message }}</p>@enderror</div>
            <div class="form-actions"><button class="button" type="submit">{{ $direct ? 'Áp dụng điều chỉnh' : 'Gửi đề nghị' }}</button><a class="text-link" href="{{ route('inventory.index') }}">Hủy</a></div>
        </form>
    </section>
</div>
@endsection
