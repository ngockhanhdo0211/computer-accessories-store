<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case CashOnDelivery = 'cod';
    case VnPay = 'vnpay';

    public function label(): string
    {
        return match ($this) {
            self::CashOnDelivery => 'Thanh toán khi nhận hàng',
            self::VnPay => 'VNPay',
        };
    }
}
