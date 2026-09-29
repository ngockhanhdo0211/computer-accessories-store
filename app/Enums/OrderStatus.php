<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Placed = 'da_dat';
    case AwaitingHandoff = 'cho_chuyen_phat';
    case InTransit = 'dang_trung_chuyen';
    case Delivered = 'da_giao';
    case Cancelled = 'da_huy';

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Placed => in_array($next, [self::AwaitingHandoff, self::Cancelled], true),
            self::AwaitingHandoff => in_array($next, [self::InTransit, self::Cancelled], true),
            self::InTransit => in_array($next, [self::Delivered, self::Cancelled], true),
            self::Delivered, self::Cancelled => false,
        };
    }
}
