@extends('layouts.storefront')
@section('title', 'Sửa phí vận chuyển')
@section('content')
<div class="shell admin-page admin-form-page shipping-rate-page">
    <header class="page-intro">
        <p class="eyebrow">Quản trị / Phí vận chuyển</p>
        <h1>{{ $shippingRate->region_key->label() }}</h1>
        <p>Nhập số nguyên VND, không dùng dấu chấm, dấu phẩy hoặc ký hiệu tiền tệ.</p>
    </header>

    <form class="form-panel" method="POST" action="{{ route('admin.shipping-rates.update', $shippingRate) }}" novalidate>
        @csrf
        @method('PUT')
        <h2>Mức phí hiện tại</h2>
        <div class="form-grid">
            <div class="field field--full">
                <label for="fee_vnd">Phí vận chuyển (VND) <span class="required-hint">(bắt buộc)</span></label>
                <input id="fee_vnd" name="fee_vnd" type="text" inputmode="numeric" pattern="[0-9]+" autocomplete="off" value="{{ old('fee_vnd', $shippingRate->fee_vnd) }}" @error('fee_vnd') aria-invalid="true" aria-describedby="fee-vnd-error" @enderror required>
                <p class="field-message @error('fee_vnd') field-error @enderror" id="fee-vnd-error">@error('fee_vnd'){{ $message }}@else Ví dụ: 30000. Mức 0 chỉ có hiệu lực khi Admin chủ động cấu hình. @enderror</p>
            </div>
        </div>
        <div class="form-actions">
            <button class="button" type="submit">Lưu mức phí</button>
            <a class="button button--quiet" href="{{ route('admin.shipping-rates.index') }}">Hủy</a>
        </div>
    </form>
</div>
@endsection
