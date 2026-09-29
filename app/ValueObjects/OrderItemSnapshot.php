<?php

namespace App\ValueObjects;

use InvalidArgumentException;

final readonly class OrderItemSnapshot
{
    public string $productName;

    public string $sku;

    public int $quantity;

    public int $unitPriceVnd;

    public int $lineSubtotalVnd;

    public int $discountVnd;

    public int $lineTotalVnd;

    public function __construct(
        mixed $productName,
        mixed $sku,
        mixed $quantity,
        mixed $unitPriceVnd,
        mixed $lineSubtotalVnd,
        mixed $discountVnd,
        mixed $lineTotalVnd,
    ) {
        if (! is_string($productName)
            || ! is_string($sku)
            || ! is_int($quantity)
            || ! is_int($unitPriceVnd)
            || ! is_int($lineSubtotalVnd)
            || ! is_int($discountVnd)
            || ! is_int($lineTotalVnd)
            || ! mb_check_encoding($productName, 'UTF-8')
            || ! mb_check_encoding($sku, 'UTF-8')
            || trim($productName) === ''
            || mb_strlen($productName) > 255
            || trim($sku) === ''
            || mb_strlen($sku) > 80
            || $quantity < 1
            || $unitPriceVnd < 0
            || $unitPriceVnd > intdiv(PHP_INT_MAX, $quantity)
            || $lineSubtotalVnd !== $unitPriceVnd * $quantity
            || $discountVnd < 0
            || $discountVnd > $lineSubtotalVnd
            || $lineTotalVnd !== $lineSubtotalVnd - $discountVnd) {
            throw new InvalidArgumentException('Order item snapshot does not reconcile.');
        }

        $this->productName = $productName;
        $this->sku = $sku;
        $this->quantity = $quantity;
        $this->unitPriceVnd = $unitPriceVnd;
        $this->lineSubtotalVnd = $lineSubtotalVnd;
        $this->discountVnd = $discountVnd;
        $this->lineTotalVnd = $lineTotalVnd;
    }
}
