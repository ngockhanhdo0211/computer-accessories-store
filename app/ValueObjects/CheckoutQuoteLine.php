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
        public ?int $cartItemId = null,
        public ?string $cartItemUpdatedAt = null,
    ) {
        if ($productId < 1
            || $categoryId < 1
            || $brandId < 1
            || trim($sku) === ''
            || trim($productName) === ''
            || $quantity < 1
            || $unitPriceVnd < 1
            || $unitPriceVnd > intdiv(PHP_INT_MAX, $quantity)
            || $lineSubtotalVnd !== $unitPriceVnd * $quantity
            || (($cartItemId === null) !== ($cartItemUpdatedAt === null))
            || ($cartItemId !== null && ($cartItemId < 1
                || ! is_string($cartItemUpdatedAt)
                || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/D', $cartItemUpdatedAt) !== 1))) {
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

    /** @return array<string, int|string> */
    public function paymentAttemptSnapshot(int $discountVnd): array
    {
        if ($this->cartItemId === null || $this->cartItemUpdatedAt === null
            || $discountVnd < 0 || $discountVnd > $this->lineSubtotalVnd) {
            throw new InvalidArgumentException('Payment Attempt line snapshot is invalid.');
        }

        return [
            'cart_item_id' => $this->cartItemId,
            'cart_item_updated_at' => $this->cartItemUpdatedAt,
            'product_id' => $this->productId,
            'category_id' => $this->categoryId,
            'brand_id' => $this->brandId,
            'sku' => $this->sku,
            'product_name' => $this->productName,
            'quantity' => $this->quantity,
            'unit_price_vnd' => $this->unitPriceVnd,
            'subtotal_vnd' => $this->lineSubtotalVnd,
            'discount_vnd' => $discountVnd,
            'line_total_vnd' => $this->lineSubtotalVnd - $discountVnd,
        ];
    }
}
