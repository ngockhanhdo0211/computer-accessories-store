<?php

namespace App\Enums;

enum CouponType: string
{
    case Percent = 'percent';
    case Fixed = 'fixed';
    case FreeShipping = 'free_shipping';

    public function label(): string
    {
        return match ($this) {
            self::Percent => 'Phần trăm',
            self::Fixed => 'Số tiền cố định',
            self::FreeShipping => 'Miễn phí vận chuyển',
        };
    }
}
