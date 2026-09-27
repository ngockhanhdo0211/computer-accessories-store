<?php

namespace App\Enums;

enum InventoryTransactionType: string
{
    case Import = 'import';
    case Sale = 'sale';
    case CancelRestore = 'cancel_restore';
    case Damaged = 'damaged';
    case ManualAdjustment = 'manual_adjustment';

    public function label(): string
    {
        return match ($this) {
            self::Import => 'Nhập kho',
            self::Sale => 'Xuất bán',
            self::CancelRestore => 'Hoàn kho do hủy',
            self::Damaged => 'Ghi nhận hàng hỏng',
            self::ManualAdjustment => 'Điều chỉnh thủ công',
        };
    }
}
