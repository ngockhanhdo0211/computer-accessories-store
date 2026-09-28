@extends('layouts.workspace')
@section('title', 'Sửa phí vận chuyển')
@section('content')
<div class="shell admin-page resource-form-page shipping-rate-page">
    <header class="page-heading">
        <div>
            <p class="eyebrow">Cấu hình giao hàng</p>
            <h1>{{ $shippingRate->region_key->label() }}</h1>
            <p>Cập nhật mức phí cho vùng nhận hàng này bằng số nguyên VND.</p>
        </div>
    </header>

    <div class="resource-form-layout">
        <form class="form-panel resource-form" method="POST" action="{{ route('admin.shipping-rates.update', $shippingRate) }}" novalidate>
            @csrf
            @method('PUT')
            <header class="resource-form__heading">
                <p class="section-label">Mức phí áp dụng</p>
                <h2>Thông tin phí vận chuyển</h2>
                <p>Không dùng dấu chấm, dấu phẩy hoặc ký hiệu tiền tệ.</p>
            </header>
            <div class="form-grid">
                <div class="field field--full">
                    <label for="fee_vnd">Phí vận chuyển (VND) <span class="required-hint">(bắt buộc)</span></label>
                    <input id="fee_vnd" name="fee_vnd" type="text" inputmode="numeric" pattern="[0-9]+" autocomplete="off" value="{{ old('fee_vnd', $shippingRate->fee_vnd) }}" aria-describedby="fee-vnd-message" @error('fee_vnd') aria-invalid="true" @enderror required>
                    <p class="field-message @error('fee_vnd') field-error @enderror" id="fee-vnd-message">@error('fee_vnd'){{ $message }}@else Ví dụ: 30000. Mức 0 chỉ có hiệu lực khi Admin chủ động cấu hình. @enderror</p>
                </div>
            </div>
            <div class="action-group resource-form__actions">
                <button class="button" type="submit">Lưu mức phí</button>
                <a class="button button--quiet" href="{{ route('admin.shipping-rates.index') }}">Hủy</a>
            </div>
        </form>

        <aside class="resource-form-guide" aria-labelledby="shipping-guide-title">
            <p class="section-label">Phạm vi thay đổi</p>
            <h2 id="shipping-guide-title">{{ $shippingRate->region_key->label() }}</h2>
            <dl class="resource-summary">
                <div><dt>Khóa vùng</dt><dd>{{ $shippingRate->region_key->value }}</dd></div>
                <div><dt>Mức hiện tại</dt><dd>{{ number_format($shippingRate->fee_vnd, 0, ',', '.') }} VND</dd></div>
            </dl>
            <p>Thay đổi chỉ áp dụng cho lần tính phí mới; các giao dịch đã lưu snapshot không bị sửa lại.</p>
        </aside>
    </div>
</div>
@endsection
