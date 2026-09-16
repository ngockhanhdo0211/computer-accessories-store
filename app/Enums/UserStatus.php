<?php

namespace App\Enums;

enum UserStatus: string
{
    case Active = 'active';
    case Locked = 'locked';
    case Inactive = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Đang hoạt động',
            self::Locked => 'Tạm khóa',
            self::Inactive => 'Ngừng sử dụng',
        };
    }
}
