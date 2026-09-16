<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Đăng nhập</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 680px; margin: 2rem auto; padding: 0 1rem; color: #222; }
        form { display: grid; gap: 1rem; }
        label { display: block; font-weight: 600; margin-bottom: .3rem; }
        input { box-sizing: border-box; width: 100%; padding: .65rem; font: inherit; }
        button { padding: .7rem 1.2rem; cursor: pointer; }
        .error { color: #b00020; margin: .3rem 0 0; }
    </style>
</head>
<body>
    <h1>Đăng nhập</h1>
    <form method="POST" action="{{ route('login') }}">
        @csrf
        <div>
            <label for="email">Email</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" maxlength="255" autocomplete="username" required>
            @error('email') <p class="error">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="password">Mật khẩu</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required>
            @error('password') <p class="error">{{ $message }}</p> @enderror
        </div>
        <button type="submit">Đăng nhập</button>
    </form>
    <p>Chưa có tài khoản? <a href="{{ route('register') }}">Đăng ký khách hàng</a></p>
    <p><a href="{{ route('home') }}">Về trang chủ</a></p>
</body>
</html>