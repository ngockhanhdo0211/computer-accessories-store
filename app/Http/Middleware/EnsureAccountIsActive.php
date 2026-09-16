<?php

namespace App\Http\Middleware;

use App\Enums\UserStatus;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $user->refresh();

        if (UserStatus::tryFrom((string) $user->getRawOriginal('status')) !== UserStatus::Active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => 'Thông tin đăng nhập không hợp lệ.']);
        }

        return $next($request);
    }
}
