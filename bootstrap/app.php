<?php

use App\Http\Controllers\Health\LivenessController;
use App\Http\Controllers\Health\ReadinessController;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\RequireRole;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        then: function (): void {
            Route::get('/up', LivenessController::class)->name('health.up');
            Route::get('/health/ready', ReadinessController::class)->name('health.ready');
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_PROTO,
        );
        $middleware->trustHosts(
            at: fn (): array => ['^'.preg_quote((string) parse_url(config('app.url'), PHP_URL_HOST), '/').'$'],
            subdomains: false,
        );
        $middleware->preventRequestsDuringMaintenance(except: ['/up', '/health/ready']);
        $middleware->alias([
            'active' => EnsureAccountIsActive::class,
            'role' => RequireRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
