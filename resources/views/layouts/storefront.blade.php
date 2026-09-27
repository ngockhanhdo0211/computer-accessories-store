<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Trạm Phụ Kiện') · Trạm Phụ Kiện</title>
    @unless(app()->runningUnitTests())
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endunless
</head>
<body>
    <a class="skip-link" href="#main-content">Đi đến nội dung chính</a>
    <header class="site-header">
        <div class="site-ribbon"><div class="shell">PHỤ KIỆN LAPTOP <span>·</span> CATALOG ĐANG HOÀN THIỆN</div></div>
        <div class="shell header-inner">
            <a class="brand" href="{{ route('home') }}" aria-label="Trạm Phụ Kiện, trang chủ">
                <span class="brand-mark" aria-hidden="true">TP</span>
                <span class="brand-text">Trạm Phụ Kiện</span>
            </a>
            <nav class="nav-main" aria-label="Điều hướng chính">
                <a href="{{ route('home') }}" @if(request()->routeIs('home')) aria-current="page" @endif>Trang chủ</a>
                <a href="{{ route('products.index') }}" @if(request()->routeIs('products.*')) aria-current="page" @endif>Cửa hàng</a>
                @guest
                    <a href="{{ route('login') }}" @if(request()->routeIs('login')) aria-current="page" @endif>Đăng nhập</a>
                    <a class="button" href="{{ route('register') }}" @if(request()->routeIs('register')) aria-current="page" @endif>Đăng ký</a>
                @else
                    @if (auth()->user()->isCustomer())
                        <a class="cart-nav-link" href="{{ route('cart.index') }}" @if(request()->routeIs('cart.*')) aria-current="page" @endif>
                            Giỏ hàng <span class="cart-count" aria-label="{{ $cartItemCount }} dòng sản phẩm">{{ $cartItemCount }}</span>
                        </a>
                    @endif
                    <span class="nav-user" title="{{ auth()->user()->name }}">{{ auth()->user()->name }}</span>
                    <a href="{{ route('dashboard') }}" @if(request()->routeIs('*.dashboard', 'dashboard')) aria-current="page" @endif>Dashboard</a>
                    <form class="inline-form" method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button class="link-button" type="submit">Đăng xuất</button>
                    </form>
                @endguest
            </nav>
            <button class="nav-toggle" type="button" aria-label="Mở menu" aria-expanded="false" aria-controls="mobile-nav" data-nav-toggle>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M3 7h18M3 12h18M3 17h18"/></svg>
            </button>
        </div>
        <nav id="mobile-nav" class="shell mobile-nav" aria-label="Điều hướng di động" hidden>
            <a href="{{ route('home') }}" @if(request()->routeIs('home')) aria-current="page" @endif>Trang chủ</a>
            <a href="{{ route('products.index') }}" @if(request()->routeIs('products.*')) aria-current="page" @endif>Cửa hàng</a>
            @guest
                <a href="{{ route('login') }}" @if(request()->routeIs('login')) aria-current="page" @endif>Đăng nhập</a>
                <a href="{{ route('register') }}" @if(request()->routeIs('register')) aria-current="page" @endif>Đăng ký</a>
            @else
                @if (auth()->user()->isCustomer())
                    <a href="{{ route('cart.index') }}" @if(request()->routeIs('cart.*')) aria-current="page" @endif>Giỏ hàng ({{ $cartItemCount }})</a>
                @endif
                <span class="nav-user">{{ auth()->user()->name }}</span>
                <a href="{{ route('dashboard') }}" @if(request()->routeIs('*.dashboard', 'dashboard')) aria-current="page" @endif>Dashboard</a>
                <form class="inline-form" method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="link-button" type="submit">Đăng xuất</button>
                </form>
            @endguest
        </nav>
    </header>
    <main id="main-content">
        @if (session('status'))
            <div class="shell"><div class="alert" role="status">{{ session('status') }}</div></div>
        @endif
        @yield('content')
    </main>
    <footer class="site-footer">
        <div class="shell footer-inner">
            <p><strong>Trạm Phụ Kiện</strong> · Phụ kiện laptop cho góc làm việc mỗi ngày. Catalog sản phẩm và tính năng mua sắm đang được hoàn thiện.</p>
            <nav class="footer-links" aria-label="Điều hướng cuối trang">
                <a href="{{ route('home') }}">Trang chủ</a>
                @guest
                    <a href="{{ route('login') }}">Đăng nhập</a>
                    <a href="{{ route('register') }}">Đăng ký</a>
                @else
                    <a href="{{ route('dashboard') }}">Dashboard</a>
                @endguest
            </nav>
        </div>
    </footer>
</body>
</html>
