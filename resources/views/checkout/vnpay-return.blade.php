@extends('layouts.storefront')
@section('title', 'Đang xác minh thanh toán VNPay')
@section('content')
<div class="shell checkout-return-page">
    <section class="form-panel checkout-return-card" aria-labelledby="vnpay-return-title">
        <p class="checkout-return-card__label">VNPay Sandbox</p>
        <h1 id="vnpay-return-title">Kết quả thanh toán đang được hệ thống xác minh.</h1>
        <p>Trang này không xác nhận giao dịch đã thành công. Trạng thái chính thức sẽ chỉ được cập nhật sau khi hệ thống xử lý thông báo bảo mật từ VNPay.</p>
        <div class="action-group">
            @auth
                @if(auth()->user()->isCustomer())
                    <a class="button" href="{{ route('orders.index') }}">Xem đơn hàng của tôi</a>
                @endif
            @else
                <a class="button button--quiet" href="{{ route('login') }}">Đăng nhập</a>
            @endauth
            <a class="button button--quiet" href="{{ route('home') }}">Về trang chủ</a>
        </div>
    </section>
</div>
@endsection
