<aside id="workspace-sidebar" class="workspace-sidebar" aria-label="Điều hướng không gian vận hành" data-workspace-sidebar>
    <div class="workspace-sidebar__brand-row">
        <a class="workspace-brand" href="{{ route(auth()->user()->role->dashboardRouteName()) }}" aria-label="Trạm Phụ Kiện, dashboard">
            <span class="brand-mark" aria-hidden="true">TP</span>
            <span>
                <strong>Trạm Phụ Kiện</strong>
                <small>Workspace</small>
            </span>
        </a>
        <button class="workspace-sidebar__close" type="button" aria-label="Đóng menu điều hướng" data-workspace-close>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg>
        </button>
    </div>

    <nav class="workspace-nav" aria-label="Điều hướng chính">
        <div class="workspace-nav__group">
            <p class="workspace-nav__label">Tổng quan</p>
            <a href="{{ route(auth()->user()->role->dashboardRouteName()) }}" @if(request()->routeIs('admin.dashboard', 'employee.dashboard')) aria-current="page" @endif>
                Dashboard
            </a>
        </div>

        @if (auth()->user()->isAdmin())
            <div class="workspace-nav__group">
                <p class="workspace-nav__label">Catalog</p>
                <a href="{{ route('admin.categories.index') }}" @if(request()->routeIs('admin.categories.*')) aria-current="page" @endif>Danh mục</a>
                <a href="{{ route('admin.brands.index') }}" @if(request()->routeIs('admin.brands.*')) aria-current="page" @endif>Thương hiệu</a>
                <a href="{{ route('admin.products.index') }}" @if(request()->routeIs('admin.products.*')) aria-current="page" @endif>Sản phẩm</a>
            </div>
        @endif

        @if (auth()->user()->isAdmin())
            <div class="workspace-nav__group">
                <p class="workspace-nav__label">Khuyến mãi</p>
                <a href="{{ route('admin.coupons.index') }}" @if(request()->routeIs('admin.coupons.*')) aria-current="page" @endif>Mã giảm giá</a>
            </div>
        @endif

        <div class="workspace-nav__group">
            <p class="workspace-nav__label">Vận hành</p>
            <a href="{{ route(auth()->user()->isAdmin() ? 'admin.support.index' : 'employee.support.index') }}" @if(request()->routeIs('admin.support.*', 'employee.support.*')) aria-current="page" @endif>Hỗ trợ khách hàng</a>
            @if (auth()->user()->isAdmin())
                <a href="{{ route('admin.orders.index') }}" @if(request()->routeIs('admin.orders.*')) aria-current="page" @endif>Đơn hàng</a>
                <a href="{{ route('admin.order-cancellation-requests.index') }}" @if(request()->routeIs('admin.order-cancellation-requests.*')) aria-current="page" @endif>Yêu cầu hủy đơn</a>
                <a href="{{ route('admin.refunds.index') }}" @if(request()->routeIs('admin.refunds.*')) aria-current="page" @endif>Hoàn tiền VNPay</a>
            @else
                <a href="{{ route('employee.orders.index') }}" @if(request()->routeIs('employee.orders.*')) aria-current="page" @endif>Đơn hàng</a>
                <a href="{{ route('employee.order-cancellation-requests.index') }}" @if(request()->routeIs('employee.order-cancellation-requests.*')) aria-current="page" @endif>Yêu cầu hủy đơn</a>
            @endif
            <a href="{{ route('inventory.index') }}" @if(request()->routeIs('inventory.*')) aria-current="page" @endif>Tồn kho</a>
            @if (auth()->user()->isAdmin())
                <a href="{{ route('admin.shipping-rates.index') }}" @if(request()->routeIs('admin.shipping-rates.*')) aria-current="page" @endif>Phí vận chuyển</a>
            @endif
        </div>

        <div class="workspace-nav__group">
            <p class="workspace-nav__label">Liên kết</p>
            <a href="{{ route('home') }}">Xem cửa hàng</a>
        </div>
    </nav>

    <div class="workspace-account">
        <div class="workspace-account__identity">
            <span title="{{ auth()->user()->name }}">{{ auth()->user()->name }}</span>
            <small>{{ auth()->user()->role->label() }}</small>
        </div>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button class="workspace-logout" type="submit">Đăng xuất</button>
        </form>
    </div>
</aside>
