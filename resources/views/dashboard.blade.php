<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 680px; margin: 2rem auto; padding: 0 1rem; color: #222; }
        button { padding: .7rem 1.2rem; cursor: pointer; }
    </style>
</head>
<body>
    <h1>Dashboard {{ auth()->user()->role->label() }}</h1>
    <p>Xin chào, {{ auth()->user()->name }}.</p>
    <p>Đây là dashboard nền tảng, chưa phải dashboard nghiệp vụ hoàn chỉnh.</p>
    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit">Đăng xuất</button>
    </form>
</body>
</html>