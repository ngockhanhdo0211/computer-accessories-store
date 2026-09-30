<header class="workspace-topbar">
    <button class="workspace-menu-toggle" type="button" aria-label="Mở menu điều hướng" aria-expanded="false" aria-controls="workspace-sidebar" data-workspace-toggle>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
        <span>Menu</span>
    </button>

    <nav class="workspace-breadcrumbs" aria-label="Đường dẫn">
        @if (request()->routeIs('admin.dashboard', 'employee.dashboard'))
            <span class="workspace-breadcrumbs__current" aria-current="page">@yield('title', 'Dashboard')</span>
        @else
            <a class="workspace-breadcrumbs__root" href="{{ route(auth()->user()->role->dashboardRouteName()) }}">Dashboard</a>
            <span class="workspace-breadcrumbs__separator workspace-breadcrumbs__root-separator" aria-hidden="true">/</span>

            @if (request()->routeIs('admin.orders.*', 'employee.orders.*'))
                @if (request()->routeIs('admin.orders.index', 'employee.orders.index'))
                    <span class="workspace-breadcrumbs__current" aria-current="page">Đơn hàng</span>
                @else
                    <a class="workspace-breadcrumbs__section" href="{{ route(auth()->user()->isAdmin() ? 'admin.orders.index' : 'employee.orders.index') }}">Đơn hàng</a>
                    <span class="workspace-breadcrumbs__separator" aria-hidden="true">/</span>
                    <span class="workspace-breadcrumbs__current" aria-current="page">@yield('title')</span>
                @endif
            @elseif (request()->routeIs('admin.categories.*'))
                @if (request()->routeIs('admin.categories.index'))
                    <span class="workspace-breadcrumbs__current" aria-current="page">Danh mục</span>
                @else
                    <a class="workspace-breadcrumbs__section" href="{{ route('admin.categories.index') }}">Danh mục</a>
                    <span class="workspace-breadcrumbs__separator" aria-hidden="true">/</span>
                    <span class="workspace-breadcrumbs__current" aria-current="page">@yield('title')</span>
                @endif
            @elseif (request()->routeIs('admin.brands.*'))
                @if (request()->routeIs('admin.brands.index'))
                    <span class="workspace-breadcrumbs__current" aria-current="page">Thương hiệu</span>
                @else
                    <a class="workspace-breadcrumbs__section" href="{{ route('admin.brands.index') }}">Thương hiệu</a>
                    <span class="workspace-breadcrumbs__separator" aria-hidden="true">/</span>
                    <span class="workspace-breadcrumbs__current" aria-current="page">@yield('title')</span>
                @endif
            @elseif (request()->routeIs('admin.products.*'))
                @if (request()->routeIs('admin.products.index'))
                    <span class="workspace-breadcrumbs__current" aria-current="page">Sản phẩm</span>
                @else
                    <a class="workspace-breadcrumbs__section" href="{{ route('admin.products.index') }}">Sản phẩm</a>
                    <span class="workspace-breadcrumbs__separator" aria-hidden="true">/</span>
                    <span class="workspace-breadcrumbs__current" aria-current="page">@yield('title')</span>
                @endif
            @elseif (request()->routeIs('admin.coupons.*'))
                @if (request()->routeIs('admin.coupons.index'))
                    <span class="workspace-breadcrumbs__current" aria-current="page">Mã giảm giá</span>
                @else
                    <a class="workspace-breadcrumbs__section" href="{{ route('admin.coupons.index') }}">Mã giảm giá</a>
                    <span class="workspace-breadcrumbs__separator" aria-hidden="true">/</span>
                    <span class="workspace-breadcrumbs__current" aria-current="page">@yield('title')</span>
                @endif
            @elseif (request()->routeIs('admin.shipping-rates.*'))
                @if (request()->routeIs('admin.shipping-rates.index'))
                    <span class="workspace-breadcrumbs__current" aria-current="page">Phí vận chuyển</span>
                @else
                    <a class="workspace-breadcrumbs__section" href="{{ route('admin.shipping-rates.index') }}">Phí vận chuyển</a>
                    <span class="workspace-breadcrumbs__separator" aria-hidden="true">/</span>
                    <span class="workspace-breadcrumbs__current" aria-current="page">@yield('title')</span>
                @endif
            @elseif (request()->routeIs('inventory.*'))
                @if (request()->routeIs('inventory.index'))
                    <span class="workspace-breadcrumbs__current" aria-current="page">Tồn kho</span>
                @else
                    <a class="workspace-breadcrumbs__section" href="{{ route('inventory.index') }}">Tồn kho</a>
                    <span class="workspace-breadcrumbs__separator" aria-hidden="true">/</span>
                    <span class="workspace-breadcrumbs__current" aria-current="page">@yield('title')</span>
                @endif
            @else
                <span class="workspace-breadcrumbs__current" aria-current="page">@yield('title', 'Không gian vận hành')</span>
            @endif
        @endif
    </nav>
</header>
