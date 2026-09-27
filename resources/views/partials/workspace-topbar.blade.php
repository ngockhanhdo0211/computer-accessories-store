<header class="workspace-topbar">
    <button class="workspace-menu-toggle" type="button" aria-label="Mở menu quản trị" aria-expanded="false" aria-controls="workspace-sidebar" data-workspace-toggle>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
        <span>Menu</span>
    </button>
    <div class="workspace-context">
        <span>Không gian vận hành</span>
        <strong>@yield('title', 'Dashboard')</strong>
    </div>
    <a class="workspace-store-link" href="{{ route('home') }}">Xem cửa hàng</a>
</header>
