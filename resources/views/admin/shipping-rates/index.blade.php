@extends('layouts.workspace')
@section('title', 'Phí vận chuyển')
@section('content')
<div class="shell admin-page resource-page shipping-rate-page">
    <header class="page-heading">
        <div>
            <p class="eyebrow">Cấu hình giao hàng</p>
            <h1>Phí vận chuyển</h1>
            <p>Hai mức phí theo vùng được quản lý tập trung và dùng để tính phí giao hàng ở phía server.</p>
        </div>
    </header>

    @if ($shippingRates->isEmpty())
        <section class="empty-state resource-empty-state" aria-labelledby="empty-title">
            <p class="eyebrow">Chưa sẵn sàng</p>
            <h2 id="empty-title">Chưa có cấu hình phí</h2>
            <p>Chạy migration Shipping Rate để khởi tạo hai vùng giao hàng bắt buộc.</p>
        </section>
    @else
        <section class="resource-ledger" aria-labelledby="shipping-rate-list-title">
            <header class="resource-ledger__heading">
                <div>
                    <p class="section-label">Bảng phí đang áp dụng</p>
                    <h2 id="shipping-rate-list-title">Theo vùng nhận hàng</h2>
                </div>
                <p><strong>{{ $shippingRates->count() }}</strong> vùng cấu hình</p>
            </header>
            <div class="resource-list resource-list--shipping" role="list" aria-label="Cấu hình phí vận chuyển">
                <div class="resource-list__columns" aria-hidden="true">
                    <span>Vùng giao hàng</span>
                    <span>Phí hiện tại</span>
                    <span>Thao tác</span>
                </div>
                @foreach ($shippingRates as $shippingRate)
                    <article class="resource-row" role="listitem">
                        <div class="resource-row__primary">
                            <p class="section-label">{{ $shippingRate->region_key->value }}</p>
                            <h3>{{ $shippingRate->region_key->label() }}</h3>
                            <p>{{ $shippingRate->region_key === \App\Enums\ShippingRegion::HaNoi ? 'Địa chỉ nhận hàng tại Hà Nội.' : 'Địa chỉ nhận hàng ngoài Hà Nội.' }}</p>
                        </div>
                        <div class="resource-cell resource-cell--amount">
                            <span class="resource-cell__label">Phí hiện tại</span>
                            <strong>{{ number_format($shippingRate->fee_vnd, 0, ',', '.') }} VND</strong>
                        </div>
                        <div class="action-group resource-row__actions">
                            <a class="button button--quiet" href="{{ route('admin.shipping-rates.edit', $shippingRate) }}">Sửa phí</a>
                        </div>
                    </article>
                @endforeach
            </div>
        </section>
    @endif

    <aside class="resource-note" aria-label="Nguyên tắc tính phí">
        <p class="section-label">Nguyên tắc hệ thống</p>
        <p>Server chọn phí theo khóa vùng; client không gửi số tiền phí. Order và payment attempt lưu snapshot nên thay đổi tại đây không sửa lịch sử giao dịch.</p>
    </aside>
</div>
@endsection
