<?php

namespace App\Enums;

enum ProductVisibility: string
{
    case Active = 'active';
    case Hidden = 'hidden';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Đang hiển thị',
            self::Hidden => 'Đang ẩn',
        };
    }
}
