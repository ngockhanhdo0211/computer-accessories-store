<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    public function create(): View
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $key = $request->throttleKey();

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            return back()->withErrors(['email' => 'Quá nhiều lần đăng nhập thất bại. Vui lòng thử lại sau.'])->onlyInput('email');
        }

        if (! Auth::attempt([
            'email' => $request->validated('email'),
            'password' => $request->validated('password'),
            'status' => UserStatus::Active->value,
        ])) {
            RateLimiter::hit($key, 60);

            return back()->withErrors(['email' => 'Thông tin đăng nhập không hợp lệ.'])->onlyInput('email');
        }

        $user = $request->user();
        $role = UserRole::tryFrom((string) $user->getRawOriginal('role'));

        if ($role === null || UserStatus::tryFrom((string) $user->getRawOriginal('status')) !== UserStatus::Active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            RateLimiter::hit($key, 60);

            return back()->withErrors(['email' => 'Thông tin đăng nhập không hợp lệ.'])->onlyInput('email');
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        $intended = $request->session()->pull('url.intended');
        $allowed = [
            route('dashboard'),
            route($role->dashboardRouteName()),
            '/dashboard',
            '/'.$role->value.'/dashboard',
        ];

        return redirect()->to(is_string($intended) && in_array($intended, $allowed, true)
            ? $intended
            : route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home')->with('status', 'Đã đăng xuất.');
    }
}
