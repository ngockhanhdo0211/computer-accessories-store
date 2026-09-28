<?php

namespace App\Enums;

enum CouponScope: string
{
    case Cart = 'cart';
    case Product = 'product';
    case Category = 'category';
    case Brand = 'brand';

    public function label(): string
    {
        return match ($this) {
            self::Cart => 'Toàn giỏ hàng',
            self::Product => 'Sản phẩm',
            self::Category => 'Danh mục',
            self::Brand => 'Thương hiệu',
        };
    }
}
