<?php

namespace App\ValueObjects;

use InvalidArgumentException;

final readonly class CheckoutQuoteLine
{
    public function __construct(
        public int $productId,
        public int $categoryId,
        public int $brandId,
        public string $sku,
        public string $productName,
        public int $quantity,
        public int $unitPriceVnd,
        public int $lineSubtotalVnd,
    ) {
        if ($productId < 1
            || $categoryId < 1
            || $brandId < 1
            || trim($sku) === ''
            || trim($productName) === ''
            || $quantity < 1
            || $unitPriceVnd < 1
            || $unitPriceVnd > intdiv(PHP_INT_MAX, $quantity)
            || $lineSubtotalVnd !== $unitPriceVnd * $quantity) {
            throw new InvalidArgumentException('Checkout quote line is invalid.');
        }
    }

    /** @return array<string, int|string> */
    public function snapshot(): array
    {
        return [
            'product_id' => $this->productId,
            'category_id' => $this->categoryId,
            'brand_id' => $this->brandId,
            'sku' => $this->sku,
            'product_name' => $this->productName,
            'quantity' => $this->quantity,
            'unit_price_vnd' => $this->unitPriceVnd,
            'line_subtotal_vnd' => $this->lineSubtotalVnd,
        ];
    }
}
