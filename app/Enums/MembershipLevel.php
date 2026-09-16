<?php

namespace App\Enums;

enum MembershipLevel: string
{
    case Dong = 'dong';
    case Bac = 'bac';
    case Vang = 'vang';
    case KimCuong = 'kim_cuong';

    public function label(): string
    {
        return match ($this) {
            self::Dong => 'Đồng',
            self::Bac => 'Bạc',
            self::Vang => 'Vàng',
            self::KimCuong => 'Kim cương',
        };
    }
}
