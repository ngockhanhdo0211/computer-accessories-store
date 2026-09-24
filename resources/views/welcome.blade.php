@extends('layouts.storefront')

@section('title', 'Phụ kiện laptop cho góc làm việc')

@section('content')
    <div class="shell shop-home">
        <section class="shop-hero" aria-labelledby="home-title">
            <div class="shop-hero__copy">
                <p class="eyebrow">Cửa hàng phụ kiện laptop</p>
                <h1 id="home-title">Góc làm việc tốt hơn bắt đầu từ phụ kiện đúng.</h1>
                <p class="shop-hero__lede">Bàn phím, chuột, hub kết nối và những món đồ nhỏ giúp chiếc laptop của bạn làm được nhiều hơn. Trạm Phụ Kiện đang chuẩn bị catalog để bạn lựa chọn dễ dàng.</p>
                <div class="hero-actions">
                    @guest
                        <a class="button" href="{{ route('register') }}">Tạo tài khoản</a>
                        <a class="text-link" href="{{ route('login') }}">Đã có tài khoản? Đăng nhập</a>
                    @else
                        <a class="button" href="{{ route('dashboard') }}">Vào dashboard</a>
                    @endguest
                </div>
                <p class="shop-hero__note">Tài khoản đã sẵn sàng · Sản phẩm đang được cập nhật</p>
            </div>
            <figure class="shop-hero__image">
                <img src="{{ asset('images/laptop-accessories-hero.jpg') }}" alt="Bộ phụ kiện laptop gồm bàn phím, chuột, hub kết nối và giá đỡ trên bàn làm việc" width="1536" height="1024" fetchpriority="high">
                <figcaption>Phụ kiện cho mọi nhịp làm việc</figcaption>
            </figure>
        </section>

        <section class="shop-range" aria-labelledby="category-title">
            <div class="shop-section-intro">
                <p class="section-label">Nhóm phụ kiện</p>
                <h2 id="category-title">Những mảnh ghép cho một setup gọn hơn.</h2>
                <p>Các nhóm hàng dưới đây là định hướng của cửa hàng. Catalog sản phẩm công khai đang được hoàn thiện.</p>
            </div>
            <ul class="shop-range__list" aria-label="Nhóm hàng dự kiến">
                <li><strong>Bàn phím &amp; chuột</strong><span>Thao tác mỗi ngày</span></li>
                <li><strong>Hub &amp; cáp kết nối</strong><span>Mở rộng không gian làm việc</span></li>
                <li><strong>Giá đỡ &amp; phụ kiện bàn</strong><span>Sắp xếp góc làm việc</span></li>
                <li><strong>Âm thanh &amp; webcam</strong><span>Họp, học và giải trí</span></li>
            </ul>
        </section>

        <section class="shop-progress" aria-labelledby="journey-title">
            <div>
                <p class="section-label">Đang phát triển</p>
                <h2 id="journey-title">Một cửa hàng được xây từ những bước cần thiết.</h2>
            </div>
            <div class="shop-progress__body">
                <p>Đăng ký và đăng nhập đã hoạt động. Quản lý danh mục dành cho Admin đã có. Xem sản phẩm, giỏ hàng và thanh toán sẽ xuất hiện ở các giai đoạn tiếp theo.</p>
                <ol class="shop-progress__steps">
                    <li><span>Đã có</span><strong>Tài khoản khách hàng</strong></li>
                    <li><span>Đã có</span><strong>Danh mục quản trị</strong></li>
                    <li><span>Tiếp theo</span><strong>Catalog và mua sắm</strong></li>
                </ol>
            </div>
        </section>

        <section class="shop-cta" aria-labelledby="account-title">
            <div>
                <p class="section-label">Trạm Phụ Kiện</p>
                <h2 id="account-title">Bắt đầu với tài khoản của bạn.</h2>
                <p>Tạo tài khoản ngay hôm nay để sẵn sàng khi cửa hàng mở thêm các tính năng mua sắm.</p>
            </div>
            @guest
                <a class="button button--light" href="{{ route('register') }}">Đăng ký khách hàng</a>
            @else
                <a class="button button--light" href="{{ route('dashboard') }}">Mở dashboard</a>
            @endguest
        </section>
    </div>
@endsection