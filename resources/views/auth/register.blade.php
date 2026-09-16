<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Đăng ký khách hàng</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 680px; margin: 2rem auto; padding: 0 1rem; color: #222; }
        form { display: grid; gap: 1rem; }
        label { display: block; font-weight: 600; margin-bottom: .3rem; }
        input, select, textarea { box-sizing: border-box; width: 100%; padding: .65rem; font: inherit; }
        button { padding: .7rem 1.2rem; cursor: pointer; }
        .error { color: #b00020; margin: .3rem 0 0; }
    </style>
</head>
<body>
    <h1>Đăng ký khách hàng</h1>
    <p>Vui lòng điền thông tin để tạo tài khoản.</p>

    <form method="POST" action="{{ route('register') }}">
        @csrf

        <div>
            <label for="name">Họ và tên</label>
            <input id="name" name="name" value="{{ old('name') }}" maxlength="255" autocomplete="name" required>
            @error('name') <p class="error">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="email">Email</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" maxlength="255" autocomplete="email" required>
            @error('email') <p class="error">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="phone">Số điện thoại</label>
            <input id="phone" name="phone" type="tel" value="{{ old('phone') }}" autocomplete="tel" required>
            @error('phone') <p class="error">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="gender">Giới tính</label>
            <select id="gender" name="gender" autocomplete="sex" required>
                <option value="">Chọn giới tính</option>
                <option value="nam" @selected(old('gender') === 'nam')>Nam</option>
                <option value="nu" @selected(old('gender') === 'nu')>Nữ</option>
            </select>
            @error('gender') <p class="error">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="dob">Ngày sinh</label>
            <input id="dob" name="dob" type="date" autocomplete="bday" value="{{ old('dob') }}" max="{{ now()->toDateString() }}" required>
            @error('dob') <p class="error">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="address">Địa chỉ</label>
            <textarea id="address" name="address" autocomplete="street-address" rows="3" maxlength="1000" required>{{ old('address') }}</textarea>
            @error('address') <p class="error">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="password">Mật khẩu</label>
            <input id="password" name="password" type="password" autocomplete="new-password" required>
            @error('password') <p class="error">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="password_confirmation">Xác nhận mật khẩu</label>
            <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
            @error('password_confirmation') <p class="error">{{ $message }}</p> @enderror
        </div>

        <button type="submit">Tạo tài khoản</button>
    </form>

    <p><a href="{{ route('home') }}">Về trang chủ</a></p>
</body>
</html>
