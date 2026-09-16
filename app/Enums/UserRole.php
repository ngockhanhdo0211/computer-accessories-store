<?php

namespace App\Enums;

enum UserRole: string
{
    case Customer = 'customer';
    case Employee = 'employee';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Customer => 'Khách hàng',
            self::Employee => 'Nhân viên',
            self::Admin => 'Quản trị viên',
        };
    }

    public function dashboardRouteName(): string
    {
        return match ($this) {
            self::Customer => 'customer.dashboard',
            self::Employee => 'employee.dashboard',
            self::Admin => 'admin.dashboard',
        };
    }
}
