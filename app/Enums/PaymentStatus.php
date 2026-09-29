<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Unpaid = 'chua_thanh_toan';
    case Paid = 'da_thanh_toan';
    case Failed = 'that_bai';
    case Refunded = 'hoan_tien';

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => 'Chưa thanh toán',
            self::Paid => 'Đã thanh toán',
            self::Failed => 'Thất bại',
            self::Refunded => 'Đã hoàn tiền',
        };
    }
}
