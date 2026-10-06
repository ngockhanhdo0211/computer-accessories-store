@extends('layouts.storefront')

@section('title', 'Đăng ký khách hàng')

@section('content')
    <div class="shell">
        <header class="page-intro">
            <h1>Đăng ký khách hàng</h1>
            <p>Điền thông tin để tạo tài khoản. Các trường đánh dấu bắt buộc cần được hoàn thành.</p>
        </header>
        <div class="auth-layout">
            <aside class="auth-aside">
                <h2>Mua sắm và theo dõi đơn dễ dàng hơn.</h2>
                <p>Tài khoản khách hàng giúp bạn quản lý giỏ hàng, đặt hàng và nhận hỗ trợ trong cùng một nơi.</p>
                <p>Đã có tài khoản? <a class="text-link" href="{{ route('login') }}">Đăng nhập</a></p>
            </aside>
            <section class="form-panel" aria-labelledby="register-form-title">
                <h2 id="register-form-title">Thông tin của bạn</h2>
                <form method="POST" action="{{ route('register') }}">
                    @csrf
                    <div class="form-grid">
                        <div class="field field--full">
                            <label for="name">Họ và tên <span class="required-hint">(bắt buộc)</span></label>
                            <input id="name" name="name" value="{{ old('name') }}" maxlength="255" autocomplete="name" required @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>
                            <p class="field-message @error('name') field-error @enderror" @error('name') id="name-error" role="alert" @enderror>@error('name') {{ $message }} @else <span aria-hidden="true">&nbsp;</span> @enderror</p>
                        </div>
                        <div class="field">
                            <label for="email">Email <span class="required-hint">(bắt buộc)</span></label>
                            <input id="email" name="email" type="email" value="{{ old('email') }}" maxlength="255" autocomplete="email" required @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
                            <p class="field-message @error('email') field-error @enderror" @error('email') id="email-error" role="alert" @enderror>@error('email') {{ $message }} @else <span aria-hidden="true">&nbsp;</span> @enderror</p>
                        </div>
                        <div class="field">
                            <label for="phone">Số điện thoại <span class="required-hint">(bắt buộc)</span></label>
                            <input id="phone" name="phone" type="tel" value="{{ old('phone') }}" autocomplete="tel" required @error('phone') aria-invalid="true" aria-describedby="phone-error" @enderror>
                            <p class="field-message @error('phone') field-error @enderror" @error('phone') id="phone-error" role="alert" @enderror>@error('phone') {{ $message }} @else <span aria-hidden="true">&nbsp;</span> @enderror</p>
                        </div>
                        <div class="field">
                            <label for="gender">Giới tính <span class="required-hint">(bắt buộc)</span></label>
                            <select id="gender" name="gender" autocomplete="sex" required @error('gender') aria-invalid="true" aria-describedby="gender-error" @enderror>
                                <option value="">Chọn giới tính</option>
                                <option value="nam" @selected(old('gender') === 'nam')>Nam</option>
                                <option value="nu" @selected(old('gender') === 'nu')>Nữ</option>
                            </select>
                            <p class="field-message @error('gender') field-error @enderror" @error('gender') id="gender-error" role="alert" @enderror>@error('gender') {{ $message }} @else <span aria-hidden="true">&nbsp;</span> @enderror</p>
                        </div>
                        <div class="field">
                            <label for="dob">Ngày sinh <span class="required-hint">(bắt buộc)</span></label>
                            <input id="dob" name="dob" type="date" autocomplete="bday" value="{{ old('dob') }}" max="{{ now()->toDateString() }}" required @error('dob') aria-invalid="true" aria-describedby="dob-error" @enderror>
                            <p class="field-message @error('dob') field-error @enderror" @error('dob') id="dob-error" role="alert" @enderror>@error('dob') {{ $message }} @else <span aria-hidden="true">&nbsp;</span> @enderror</p>
                        </div>
                        <div class="field field--full">
                            <label for="address">Địa chỉ <span class="required-hint">(bắt buộc)</span></label>
                            <textarea id="address" name="address" autocomplete="street-address" rows="3" maxlength="1000" required @error('address') aria-invalid="true" aria-describedby="address-error" @enderror>{{ old('address') }}</textarea>
                            <p class="field-message @error('address') field-error @enderror" @error('address') id="address-error" role="alert" @enderror>@error('address') {{ $message }} @else <span aria-hidden="true">&nbsp;</span> @enderror</p>
                        </div>
                        <div class="field">
                            <label for="password">Mật khẩu <span class="required-hint">(bắt buộc)</span></label>
                            <input id="password" name="password" type="password" autocomplete="new-password" required @error('password') aria-invalid="true" aria-describedby="password-error" @enderror>
                            <p class="field-message @error('password') field-error @enderror" @error('password') id="password-error" role="alert" @enderror>@error('password') {{ $message }} @else <span aria-hidden="true">&nbsp;</span> @enderror</p>
                        </div>
                        <div class="field">
                            <label for="password_confirmation">Xác nhận mật khẩu <span class="required-hint">(bắt buộc)</span></label>
                            <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required @error('password_confirmation') aria-invalid="true" aria-describedby="password-confirmation-error" @enderror>
                            <p class="field-message @error('password_confirmation') field-error @enderror" @error('password_confirmation') id="password-confirmation-error" role="alert" @enderror>@error('password_confirmation') {{ $message }} @else <span aria-hidden="true">&nbsp;</span> @enderror</p>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button class="button" type="submit">Tạo tài khoản</button>
                        <p>Đã đăng ký? <a class="text-link" href="{{ route('login') }}">Đăng nhập</a></p>
                    </div>
                </form>
            </section>
        </div>
    </div>
@endsection
