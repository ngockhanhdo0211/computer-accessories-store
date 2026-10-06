@extends(auth()->user()->isEmployee() ? 'layouts.workspace' : 'layouts.storefront')

@section('title', 'Dashboard')

@section('content')
@if(auth()->user()->isEmployee())
    <div class="shell employee-dashboard">
        <header class="page-heading employee-dashboard__heading">
            <div>
                <p class="eyebrow">Ca làm việc</p>
                <h1>Xin chào, {{ auth()->user()->name }}</h1>
                <p>Chọn khu vực cần xử lý trong ca làm việc hiện tại.</p>
            </div>
            <span class="role-tag">{{ auth()->user()->role->label() }}</span>
        </header>

        <nav class="employee-dashboard__tasks" aria-label="Công việc vận hành">
            <a class="employee-task employee-task--primary" href="{{ route('employee.orders.index') }}"><span>01</span><strong>Đơn hàng</strong><small>Tra cứu và cập nhật tiến trình</small></a>
            <a class="employee-task" href="{{ route('inventory.index') }}"><span>02</span><strong>Tồn kho</strong><small>Kiểm tra và ghi nhận biến động</small></a>
            <a class="employee-task" href="{{ route('employee.order-cancellation-requests.index') }}"><span>03</span><strong>Yêu cầu hủy</strong><small>Xem và xử lý yêu cầu mới</small></a>
            <a class="employee-task" href="{{ route('employee.support.index') }}"><span>04</span><strong>Hỗ trợ khách hàng</strong><small>Tiếp tục các hội thoại cần phản hồi</small></a>
        </nav>

        <div class="employee-dashboard__footer">
            <a class="text-link" href="{{ route('home') }}">Xem cửa hàng</a>
            <form method="POST" action="{{ route('logout') }}">@csrf<button class="button button--quiet" type="submit">Đăng xuất</button></form>
        </div>
    </div>
@else
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
                @if(auth()->user()->isEmployee())
                    <a class="button" href="{{ route('inventory.index') }}">Mở quản lý tồn kho</a>
                @endif
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
@endif
@endsection
