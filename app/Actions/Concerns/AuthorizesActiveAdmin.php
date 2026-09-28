<?php

namespace App\Actions\Concerns;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Validation\ValidationException;

trait AuthorizesActiveAdmin
{
    protected function assertActiveAdmin(User $user): void
    {
        if (UserRole::tryFrom((string) $user->getRawOriginal('role')) !== UserRole::Admin
            || UserStatus::tryFrom((string) $user->getRawOriginal('status')) !== UserStatus::Active) {
            throw ValidationException::withMessages(['authorization' => 'Tài khoản không có quyền quản lý mã giảm giá.']);
        }
    }
}
