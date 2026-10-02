@extends('layouts.storefront')
@section('title', 'Đang xác minh thanh toán VNPay')
@section('content')
<main class="shell checkout-return-page">
    <section class="form-panel checkout-return-card" aria-labelledby="vnpay-return-title">
        <p class="eyebrow">VNPay</p>
        <h1 id="vnpay-return-title">Kết quả thanh toán đang được hệ thống xác minh.</h1>
        <p>Trang này không xác nhận giao dịch đã thành công. Trạng thái chính thức sẽ chỉ được cập nhật sau khi hệ thống xử lý thông báo bảo mật từ VNPay.</p>
        <div class="action-group">
            <a class="button" href="{{ route('home') }}">Về trang chủ</a>
            @guest
                <a class="button button--quiet" href="{{ route('login') }}">Đăng nhập</a>
            @endguest
        </div>
    </section>
</main>
@endsection
