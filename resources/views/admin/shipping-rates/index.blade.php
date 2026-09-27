@extends('layouts.workspace')
@section('title', 'Phí vận chuyển')
@section('content')
<div class="shell admin-page shipping-rate-page">
    <header class="admin-heading">
        <div>
            <p class="eyebrow">Quản trị / Phí vận chuyển</p>
            <h1>Phí vận chuyển</h1>
            <p>Hai mức phí tập trung được Checkout sử dụng sau này. Giỏ hàng hiện tại chưa cộng phí vận chuyển.</p>
        </div>
    </header>

    @if ($shippingRates->isEmpty())
        <section class="admin-empty" aria-labelledby="empty-title">
            <h2 id="empty-title">Chưa có cấu hình phí</h2>
            <p>Chạy migration Shipping Rate để khởi tạo hai vùng giao hàng bắt buộc.</p>
        </section>
    @else
        <div class="shipping-rate-list" role="list" aria-label="Cấu hình phí vận chuyển">
            @foreach ($shippingRates as $shippingRate)
                <article class="shipping-rate-row" role="listitem">
                    <div>
                        <p class="section-label">{{ $shippingRate->region_key->value }}</p>
                        <h2>{{ $shippingRate->region_key->label() }}</h2>
                        <p>{{ $shippingRate->region_key === \App\Enums\ShippingRegion::HaNoi ? 'Áp dụng cho địa chỉ nhận hàng tại Hà Nội.' : 'Áp dụng cho địa chỉ nhận hàng ngoài Hà Nội.' }}</p>
                    </div>
                    <div class="shipping-rate-row__fee">
                        <span>Phí hiện tại</span>
                        <strong>{{ number_format($shippingRate->fee_vnd, 0, ',', '.') }} VND</strong>
                    </div>
                    <a class="button button--quiet" href="{{ route('admin.shipping-rates.edit', $shippingRate) }}">Sửa phí</a>
                </article>
            @endforeach
        </div>
    @endif

    <aside class="form-note shipping-rate-note" aria-label="Nguyên tắc tính phí">
        Server chọn phí theo khóa vùng; client không được gửi số tiền phí. Order và payment attempt sau này sẽ lưu snapshot nên thay đổi tại đây không sửa lịch sử giao dịch.
    </aside>
</div>
@endsection
