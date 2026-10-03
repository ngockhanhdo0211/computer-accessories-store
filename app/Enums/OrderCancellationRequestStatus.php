<?php

namespace App\Enums;

enum OrderCancellationRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Chờ xử lý',
            self::Approved => 'Đã chấp thuận',
            self::Rejected => 'Đã từ chối',
        };
    }
}
