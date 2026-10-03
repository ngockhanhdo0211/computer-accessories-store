<?php

namespace App\Enums;

enum RefundReason: string
{
    case StockUnavailable = 'stock_unavailable';
    case CouponCapacityUnavailable = 'coupon_capacity_unavailable';
    case SnapshotIncomplete = 'snapshot_incomplete';
}
