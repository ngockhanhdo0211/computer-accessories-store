@extends('layouts.storefront')

@section('title', 'Phụ kiện cho góc làm việc')

@section('content')
    <div class="shell">
        <section class="hero" aria-labelledby="home-title">
            <div class="hero-copy">
                <p class="eyebrow">Cửa hàng phụ kiện máy tính</p>
                <h1 id="home-title">Góc làm việc bắt đầu từ những chi tiết nhỏ.</h1>
                <p class="hero-lede">Trạm Phụ Kiện đang xây dựng một nơi để khám phá và chọn phụ kiện cho máy tính của bạn. Tài khoản khách hàng đã sẵn sàng; danh mục sẽ xuất hiện trong giai đoạn tiếp theo.</p>
                <div class="hero-actions">
                    @guest
                        <a class="button" href="{{ route('register') }}">Tạo tài khoản</a>
                        <a class="button button--outline" href="{{ route('login') }}">Đăng nhập</a>
                    @else
                        <a class="button" href="{{ route('dashboard') }}">Vào dashboard</a>
                    @endguest
                </div>
            </div>
            <figure class="hero-visual">
                <svg class="hero-diagram" viewBox="0 0 520 390" aria-hidden="true" focusable="false">
                    <g transform="rotate(-8 245 235)">
                        <rect class="device" x="48" y="137" width="366" height="185" rx="13"/>
                        <path class="detail" d="M68 180h326M68 222h326M68 264h326M88 158v148M130 158v148M172 158v148M214 158v148M256 158v148M298 158v148M340 158v148M382 158v148"/>
                        <rect class="device" x="164" y="275" width="172" height="28" rx="5"/>
                    </g>
                    <g transform="rotate(16 419 108)">
                        <path class="device" d="M419 26c-30 0-54 27-54 62v42c0 36 24 61 54 61s54-25 54-61V88c0-35-24-62-54-62Z"/>
                        <path class="detail" d="M419 27v61m-53 0h106m-53-20v25"/>
                    </g>
                    <path class="detail" d="M13 45h70M48 10v70M450 309h58M479 280v58"/>
                </svg>
                <figcaption class="hero-caption">Minh họa · bàn phím &amp; chuột</figcaption>
            </figure>
        </section>

        <section class="home-section" aria-labelledby="category-title">
            <div class="section-copy">
                <p class="section-label">Hướng danh mục</p>
                <h2 id="category-title">Từ bàn phím đến từng kết nối.</h2>
                <p>Dự án hướng tới nhiều nhóm phụ kiện cho học tập, làm việc và giải trí. Danh mục hiện chỉ là định hướng; chưa có sản phẩm được đăng bán.</p>
            </div>
            <div class="section-proof">
                <ul class="category-preview" aria-label="Nhóm hàng dự kiến">
                    <li><span>Bàn phím &amp; chuột</span><small>Dự kiến</small></li>
                    <li><span>Âm thanh &amp; webcam</span><small>Dự kiến</small></li>
                    <li><span>Lưu trữ &amp; kết nối</span><small>Dự kiến</small></li>
                    <li><span>Giá đỡ &amp; gaming</span><small>Dự kiến</small></li>
                </ul>
            </div>
        </section>

        <section class="home-section home-section--reverse" aria-labelledby="journey-title">
            <div class="section-copy">
                <p class="section-label">Lộ trình sử dụng</p>
                <h2 id="journey-title">Một hành trình mua sắm rõ ràng.</h2>
                <p>Thiết kế sản phẩm sẽ hỗ trợ tìm kiếm, giỏ hàng, thanh toán và theo dõi đơn. Các tính năng này đang nằm trong lộ trình phát triển, chưa hoạt động trên website.</p>
            </div>
            <div class="section-proof">
                <ol class="process-list">
                    <li><span>01</span><strong>Khám phá phụ kiện phù hợp</strong></li>
                    <li><span>02</span><strong>Xem thông tin và chọn sản phẩm</strong></li>
                    <li><span>03</span><strong>Theo dõi đơn trong tài khoản</strong></li>
                </ol>
            </div>
        </section>

        <section class="closing-band" aria-labelledby="account-title">
            <div>
                <p class="section-label">Bắt đầu</p>
                <h2 id="account-title">Tài khoản khách hàng đã sẵn sàng.</h2>
            </div>
            @guest
                <a class="button" href="{{ route('register') }}">Đăng ký khách hàng</a>
            @else
                <a class="button" href="{{ route('dashboard') }}">Mở dashboard</a>
            @endguest
        </section>
    </div>
@endsection