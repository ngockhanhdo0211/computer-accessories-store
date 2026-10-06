@extends('layouts.workspace')

@section('title', 'Tổng quan quản trị')

@section('content')
<div class="shell admin-dashboard">
    <header class="admin-dashboard__header">
        <div>
            <p class="eyebrow">Quản trị / Tổng quan</p>
            <h1>Tổng quan quản trị</h1>
            <p>Số liệu tài khoản và danh mục hiện có tại thời điểm mở trang.</p>
        </div>
    </header>

    <section class="admin-dashboard__summary" aria-labelledby="summary-title">
        <div class="admin-dashboard__section-head">
            <div><h2 id="summary-title">Tình hình hiện tại</h2><p>Các chỉ số được tổng hợp trực tiếp từ dữ liệu hệ thống.</p></div>
            <nav class="action-group" aria-label="Tác vụ nhanh">
                <a class="button" href="{{ route('admin.orders.index') }}">Xử lý đơn hàng</a>
                <a class="button button--quiet" href="{{ route('inventory.index') }}">Kiểm tra tồn kho</a>
            </nav>
        </div>
        <dl class="admin-dashboard__kpis">
            <div class="admin-dashboard__kpi-lead">
                <dt>Tổng tài khoản</dt>
                <dd id="metric-users-total">{{ number_format($stats['users']['total'], 0, ',', '.') }}</dd>
            </div>
            <div><dt>Khách hàng</dt><dd>{{ number_format($stats['users']['roles']['customer'], 0, ',', '.') }}</dd></div>
            <div><dt>Nhân viên</dt><dd>{{ number_format($stats['users']['roles']['employee'], 0, ',', '.') }}</dd></div>
            <div><dt>Quản trị viên</dt><dd>{{ number_format($stats['users']['roles']['admin'], 0, ',', '.') }}</dd></div>
            <div><dt>Tổng danh mục</dt><dd>{{ number_format($stats['categories']['total'], 0, ',', '.') }}</dd></div>
            <div><dt>Danh mục hiển thị</dt><dd>{{ number_format($stats['categories']['visible'], 0, ',', '.') }}</dd></div>
        </dl>
    </section>

    <div class="admin-dashboard__analysis">
        <section class="admin-dashboard__roles" aria-labelledby="roles-title">
            <h2 id="roles-title">Phân bố vai trò</h2>
            <p id="roles-description">Số tài khoản theo từng vai trò, tính trên tổng tài khoản hiện có.</p>
            <div class="admin-dashboard__role-list" role="group" aria-describedby="roles-description">
                @foreach (['customer' => 'Khách hàng', 'employee' => 'Nhân viên', 'admin' => 'Quản trị viên'] as $role => $label)
                    <div class="admin-dashboard__role-row">
                        <div class="admin-dashboard__role-line">
                            <strong>{{ $label }}</strong>
                            <span>{{ number_format($stats['users']['roles'][$role], 0, ',', '.') }} · {{ $stats['users']['role_shares'][$role] }}%</span>
                        </div>
                        <progress max="100" value="{{ $stats['users']['role_shares'][$role] }}" aria-label="{{ $label }}: {{ $stats['users']['roles'][$role] }} tài khoản, {{ $stats['users']['role_shares'][$role] }}%">{{ $stats['users']['role_shares'][$role] }}%</progress>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="admin-dashboard__statuses" aria-labelledby="statuses-title">
            <h2 id="statuses-title">Trạng thái tài khoản</h2>
            <p>Phân loại theo trạng thái đăng nhập hiện tại.</p>
            <dl>
                <div><dt>Đang hoạt động</dt><dd>{{ number_format($stats['users']['statuses']['active'], 0, ',', '.') }}</dd></div>
                <div><dt>Tạm khóa</dt><dd>{{ number_format($stats['users']['statuses']['locked'], 0, ',', '.') }}</dd></div>
                <div><dt>Ngừng sử dụng</dt><dd>{{ number_format($stats['users']['statuses']['inactive'], 0, ',', '.') }}</dd></div>
            </dl>
        </section>
    </div>

    <section class="admin-dashboard__categories" aria-labelledby="categories-title">
        <div class="admin-dashboard__section-head">
            <div>
                <h2 id="categories-title">Danh mục</h2>
                <p>Cấu trúc cha–con và trạng thái hiển thị của catalog hiện có.</p>
            </div>
            <a class="text-link" href="{{ route('admin.categories.index') }}">Quản lý danh mục</a>
        </div>
        <dl class="admin-dashboard__category-counts">
            <div><dt>Tổng danh mục</dt><dd>{{ number_format($stats['categories']['total'], 0, ',', '.') }}</dd></div>
            <div><dt>Danh mục gốc</dt><dd>{{ number_format($stats['categories']['roots'], 0, ',', '.') }}</dd></div>
            <div><dt>Danh mục con</dt><dd>{{ number_format($stats['categories']['children'], 0, ',', '.') }}</dd></div>
            <div><dt>Đang hiển thị</dt><dd>{{ number_format($stats['categories']['visible'], 0, ',', '.') }}</dd></div>
            <div><dt>Đang ẩn</dt><dd>{{ number_format($stats['categories']['hidden'], 0, ',', '.') }}</dd></div>
        </dl>
    </section>

    <section class="admin-dashboard__categories" aria-labelledby="inventory-title">
        <div class="admin-dashboard__section-head"><div><h2 id="inventory-title">Tồn kho</h2><p>Tình trạng hàng hóa và các đề nghị đang chờ xử lý.</p></div><a class="text-link" href="{{ route('inventory.index') }}">Quản lý tồn kho</a></div>
        <dl class="admin-dashboard__category-counts">
            <div><dt>Tổng sản phẩm</dt><dd>{{ number_format($stats['inventory']['products'], 0, ',', '.') }}</dd></div>
            <div><dt>Sắp hết</dt><dd>{{ number_format($stats['inventory']['low_stock'], 0, ',', '.') }}</dd></div>
            <div><dt>Hết hàng</dt><dd>{{ number_format($stats['inventory']['out_of_stock'], 0, ',', '.') }}</dd></div>
            <div><dt>Đề nghị chờ duyệt</dt><dd>{{ number_format($stats['inventory']['pending_adjustments'], 0, ',', '.') }}</dd></div>
        </dl>
    </section>

    <nav class="admin-dashboard__workbench" aria-labelledby="workbench-title">
        <div>
            <p class="eyebrow">Lối tắt vận hành</p>
            <h2 id="workbench-title">Tiếp tục công việc</h2>
            <p>Mở thẳng khu vực cần xử lý mà không rời trung tâm vận hành.</p>
        </div>
        <div class="admin-dashboard__workbench-links">
            <a href="{{ route('admin.orders.index') }}"><strong>Đơn hàng</strong><span>Tra cứu và cập nhật trạng thái</span></a>
            <a href="{{ route('admin.support.index') }}"><strong>Hỗ trợ khách hàng</strong><span>Đọc và phản hồi hội thoại</span></a>
            <a href="{{ route('inventory.adjustments.index') }}"><strong>Điều chỉnh kho</strong><span>Duyệt đề nghị đang chờ</span></a>
            <a href="{{ route('admin.refunds.index') }}"><strong>Hoàn tiền VNPay</strong><span>Theo dõi và đối soát kết quả</span></a>
            <a href="{{ route('admin.products.index') }}"><strong>Sản phẩm</strong><span>Cập nhật nội dung bán hàng</span></a>
            <a href="{{ route('admin.categories.create') }}"><strong>Tạo danh mục</strong><span>Mở rộng cấu trúc cửa hàng</span></a>
        </div>
    </nav>
</div>
@endsection
