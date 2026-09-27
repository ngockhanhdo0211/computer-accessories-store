<?php

namespace App\Enums;

enum ShippingRegion: string
{
    case HaNoi = 'ha_noi';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::HaNoi => 'Hà Nội',
            self::Other => 'Tỉnh/thành khác',
        };
    }
}
