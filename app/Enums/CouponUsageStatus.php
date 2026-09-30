<?php

namespace App\Enums;

enum CouponUsageStatus: string
{
    case Reserved = 'reserved';
    case Consumed = 'consumed';
    case Released = 'released';
}
