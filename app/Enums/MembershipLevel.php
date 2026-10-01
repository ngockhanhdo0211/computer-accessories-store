<?php

namespace App\Enums;

enum MembershipLevel: string
{
    case Dong = 'dong';
    case Bac = 'bac';
    case Vang = 'vang';
    case KimCuong = 'kim_cuong';

    public static function fromSpendingVnd(int $spendingVnd): self
    {
        if ($spendingVnd < 0) {
            throw new \InvalidArgumentException('Membership spending cannot be negative.');
        }

        return match (true) {
            $spendingVnd >= 30_000_000 => self::KimCuong,
            $spendingVnd >= 15_000_000 => self::Vang,
            $spendingVnd >= 5_000_000 => self::Bac,
            default => self::Dong,
        };
    }

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
