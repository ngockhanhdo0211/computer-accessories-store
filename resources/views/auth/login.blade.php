@extends('layouts.storefront')

@section('title', 'Đăng nhập')

@section('content')
    <div class="shell">
        <header class="page-intro">
            <h1>Đăng nhập</h1>
            <p>Tiếp tục mua sắm, theo dõi đơn hàng và trò chuyện với đội ngũ hỗ trợ.</p>
        </header>
        <div class="auth-layout">
            <aside class="auth-aside">
                <h2>Mọi đơn hàng ở cùng một nơi.</h2>
                <p>Đăng nhập để xem giỏ hàng, trạng thái thanh toán và lịch sử giao hàng của bạn.</p>
                <a class="text-link" href="{{ route('products.index') }}">Xem cửa hàng</a>
            </aside>
            <section class="form-panel" aria-labelledby="login-form-title">
                <h2 id="login-form-title">Thông tin đăng nhập</h2>
                <form method="POST" action="{{ route('login') }}">
                    @csrf
                    <div class="form-grid">
                        <div class="field field--full">
                            <label for="email">Email <span class="required-hint">(bắt buộc)</span></label>
                            <input id="email" name="email" type="email" value="{{ old('email') }}" maxlength="255" autocomplete="username" required @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
                            <p class="field-message @error('email') field-error @enderror" @error('email') id="email-error" role="alert" @enderror>@error('email') {{ $message }} @else <span aria-hidden="true">&nbsp;</span> @enderror</p>
                        </div>
                        <div class="field field--full">
                            <label for="password">Mật khẩu <span class="required-hint">(bắt buộc)</span></label>
                            <input id="password" name="password" type="password" autocomplete="current-password" required @error('password') aria-invalid="true" aria-describedby="password-error" @enderror>
                            <p class="field-message @error('password') field-error @enderror" @error('password') id="password-error" role="alert" @enderror>@error('password') {{ $message }} @else <span aria-hidden="true">&nbsp;</span> @enderror</p>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button class="button" type="submit">Đăng nhập</button>
                        <p>Chưa có tài khoản? <a class="text-link" href="{{ route('register') }}">Đăng ký khách hàng</a></p>
                    </div>
                </form>
            </section>
        </div>
    </div>
@endsection
