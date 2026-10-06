@extends('layouts.storefront')

@section('title', 'Phụ kiện laptop cho góc làm việc')

@section('content')
    <div class="shop-home">
        <section class="shop-hero" aria-labelledby="home-title">
            <div class="shell shop-hero__inner">
            <div class="shop-hero__copy">
                <p class="shop-hero__category">Phụ kiện máy tính chọn lọc</p>
                <h1 id="home-title">Hoàn thiện góc máy của bạn.</h1>
                <p class="shop-hero__lede">Khám phá bàn phím, chuột, hub kết nối và phụ kiện thiết thực với giá bán, ưu đãi và tình trạng hàng được hiển thị rõ ràng.</p>
                <div class="hero-actions">
                    <a class="button" href="{{ route('products.index') }}">Mua sắm ngay</a>
                    @guest
                        <a class="button button--quiet" href="{{ route('register') }}">Tạo tài khoản</a>
                    @else
                        @if(auth()->user()->isCustomer())
                            <a class="button button--quiet" href="{{ route('cart.index') }}">Xem giỏ hàng</a>
                        @endif
                    @endguest
                </div>
                <ul class="shop-hero__benefits" aria-label="Lợi ích mua sắm">
                    <li>Giá bán minh bạch</li>
                    <li>Tồn kho cập nhật</li>
                    <li>COD và VNPay Sandbox</li>
                </ul>
            </div>
            <figure class="shop-hero__image">
                <img src="{{ asset('images/laptop-accessories-hero.jpg') }}" alt="Bộ phụ kiện laptop gồm bàn phím, chuột, hub kết nối và giá đỡ trên bàn làm việc" width="1536" height="1024" fetchpriority="high">
                <figcaption>Phụ kiện thiết thực cho góc máy mỗi ngày</figcaption>
            </figure>
            </div>
        </section>

        <section class="shell shop-range" aria-labelledby="category-title">
            <div class="shop-section-intro">
                <h2 id="category-title">Mua sắm rõ ràng từ sản phẩm đến thanh toán.</h2>
                <p>Mỗi sản phẩm đều có thông tin giá, thương hiệu và tồn kho để bạn so sánh trước khi thêm vào giỏ.</p>
                <a class="text-link" href="{{ route('products.index') }}">Xem toàn bộ sản phẩm</a>
            </div>
            <ul class="shop-range__list" aria-label="Trải nghiệm mua sắm">
                <li><strong>Chọn sản phẩm</strong><span>Lọc theo danh mục và thương hiệu</span></li>
                <li><strong>Kiểm tra giỏ hàng</strong><span>Xem đơn giá, số lượng và thành tiền</span></li>
                <li><strong>Nhận báo giá</strong><span>Áp dụng phí giao hàng và mã giảm giá</span></li>
                <li><strong>Theo dõi đơn</strong><span>Xem trạng thái và lịch sử xử lý</span></li>
            </ul>
        </section>

        <section class="shell shop-cta" aria-labelledby="account-title">
            <div>
                <h2 id="account-title">Sẵn sàng chọn phụ kiện phù hợp?</h2>
                <p>Tạo tài khoản để lưu giỏ hàng, đặt hàng và theo dõi tiến trình giao hàng.</p>
            </div>
            @guest
                <a class="button button--light" href="{{ route('register') }}">Tạo tài khoản</a>
            @else
                <a class="button button--light" href="{{ route('products.index') }}">Xem sản phẩm</a>
            @endguest
        </section>
    </div>
@endsection
