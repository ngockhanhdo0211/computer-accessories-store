<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $allowed = array_filter(array_map(UserRole::tryFrom(...), $roles));

        $role = UserRole::tryFrom((string) $request->user()->getRawOriginal('role'));
        abort_unless($role !== null && in_array($role, $allowed, true), 403);

        return $next($request);
    }
}
