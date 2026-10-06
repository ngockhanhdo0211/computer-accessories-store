<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Không gian vận hành') · Trạm Phụ Kiện</title>
    @unless(app()->runningUnitTests())
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endunless
</head>
<body class="workspace-body">
    <a class="skip-link" href="#workspace-main">Đi đến nội dung chính</a>

    <div class="workspace-shell" data-workspace-shell>
        @include('partials.workspace-sidebar')

        <div class="workspace-stage">
            @include('partials.workspace-topbar')

            <main id="workspace-main" class="workspace-main" tabindex="-1">
                @if (session('status'))
                    <div class="alert workspace-alert" role="status">{{ session('status') }}</div>
                @endif
                @yield('content')
            </main>

            <footer class="workspace-footer">
                <span>Trạm Phụ Kiện · Trung tâm vận hành</span>
                <span>Phiên làm việc: {{ auth()->user()->role->label() }}</span>
            </footer>
        </div>

        <button class="workspace-overlay" type="button" aria-label="Đóng menu điều hướng" data-workspace-overlay hidden></button>
    </div>
</body>
</html>
