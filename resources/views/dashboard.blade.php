@extends('layouts.storefront')

@section('title', 'Dashboard')

@section('content')
    <div class="shell">
        <header class="page-intro">
            <p class="eyebrow">Tài khoản / Dashboard</p>
            <h1>Dashboard nền tảng</h1>
            <p>Không gian tài khoản của bạn tại Trạm Phụ Kiện.</p>
        </header>
        <div class="dashboard-layout">
            <section class="dashboard-intro" aria-labelledby="welcome-title">
                <span class="role-tag">{{ auth()->user()->role->label() }}</span>
                <h2 id="welcome-title">Xin chào, {{ auth()->user()->name }}.</h2>
                <p>Đây là dashboard nền tảng. Các module nghiệp vụ cho {{ auth()->user()->role->label() }} sẽ được xây dựng ở những giai đoạn tiếp theo.</p>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="button button--outline" type="submit">Đăng xuất</button>
                </form>
            </section>
            <aside class="dashboard-empty" aria-labelledby="empty-title">
                <svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false"><rect x="5" y="8" width="38" height="32" rx="3"/><path d="M5 18h38M13 27h10M13 33h18"/></svg>
                <p class="section-label">Không gian sắp tới</p>
                <h2 id="empty-title">Chưa có dữ liệu nghiệp vụ.</h2>
                <p>Thông tin đơn hàng, catalog và công cụ quản lý sẽ xuất hiện khi các phần tương ứng được triển khai.</p>
                <a class="text-link" href="{{ route('home') }}">Về trang chủ</a>
            </aside>
        </div>
    </div>
@endsection