<?php

namespace App\ValueObjects;

use InvalidArgumentException;

final readonly class OrderSnapshot
{
    public string $recipientName;

    public string $recipientEmail;

    public string $recipientPhone;

    public string $recipientAddress;

    public string $recipientRegion;

    /** @var array<string, mixed>|null */
    public ?array $coupon;

    public int $itemsSubtotalVnd;

    public int $itemDiscountVnd;

    public int $shippingFeeVnd;

    public int $shippingDiscountVnd;

    public int $totalVnd;

    /** @param array<string, mixed>|null $coupon */
    public function __construct(
        mixed $recipientName,
        mixed $recipientEmail,
        mixed $recipientPhone,
        mixed $recipientAddress,
        mixed $recipientRegion,
        mixed $coupon,
        mixed $itemsSubtotalVnd,
        mixed $itemDiscountVnd,
        mixed $shippingFeeVnd,
        mixed $shippingDiscountVnd,
        mixed $totalVnd,
    ) {
        if (! is_string($recipientName)
            || ! is_string($recipientEmail)
            || ! is_string($recipientPhone)
            || ! is_string($recipientAddress)
            || ! is_string($recipientRegion)
            || ($coupon !== null && ! is_array($coupon))
            || ! is_int($itemsSubtotalVnd)
            || ! is_int($itemDiscountVnd)
            || ! is_int($shippingFeeVnd)
            || ! is_int($shippingDiscountVnd)
            || ! is_int($totalVnd)) {
            throw new InvalidArgumentException('Order snapshot values must use exact scalar types.');
        }

        foreach ([
            [$recipientName, 255],
            [$recipientEmail, 255],
            [$recipientPhone, 40],
            [$recipientAddress, 2000],
            [$recipientRegion, 100],
        ] as [$value, $maximum]) {
            if (! mb_check_encoding($value, 'UTF-8')
                || trim($value) === ''
                || mb_strlen($value) > $maximum) {
                throw new InvalidArgumentException('Order recipient snapshot is invalid.');
            }
        }

        if (filter_var($recipientEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Order recipient email is invalid.');
        }

        foreach ([$itemsSubtotalVnd, $itemDiscountVnd, $shippingFeeVnd, $shippingDiscountVnd, $totalVnd] as $money) {
            if ($money < 0) {
                throw new InvalidArgumentException('Order money must be a non-negative integer.');
            }
        }

        if ($itemDiscountVnd > $itemsSubtotalVnd
            || $shippingDiscountVnd > $shippingFeeVnd
            || $itemDiscountVnd > PHP_INT_MAX - $shippingDiscountVnd) {
            throw new InvalidArgumentException('Order pricing snapshot does not reconcile.');
        }

        $itemsAfterDiscount = $itemsSubtotalVnd - $itemDiscountVnd;
        $shippingAfterDiscount = $shippingFeeVnd - $shippingDiscountVnd;

        if ($shippingAfterDiscount > PHP_INT_MAX - $itemsAfterDiscount
            || $totalVnd !== $itemsAfterDiscount + $shippingAfterDiscount) {
            throw new InvalidArgumentException('Order pricing snapshot does not reconcile.');
        }

        if ($coupon !== null) {
            $this->assertCoupon($coupon);
        }

        $this->recipientName = $recipientName;
        $this->recipientEmail = $recipientEmail;
        $this->recipientPhone = $recipientPhone;
        $this->recipientAddress = $recipientAddress;
        $this->recipientRegion = $recipientRegion;
        $this->coupon = $coupon;
        $this->itemsSubtotalVnd = $itemsSubtotalVnd;
        $this->itemDiscountVnd = $itemDiscountVnd;
        $this->shippingFeeVnd = $shippingFeeVnd;
        $this->shippingDiscountVnd = $shippingDiscountVnd;
        $this->totalVnd = $totalVnd;
    }

    /** @param array<string, mixed> $coupon */
    private function assertCoupon(array $coupon): void
    {
        $keys = ['coupon_id', 'code', 'type', 'scope', 'value', 'eligible_subtotal_vnd'];
        $actualKeys = array_keys($coupon);
        sort($keys);
        sort($actualKeys);

        if ($keys !== $actualKeys
            || ! is_int($coupon['coupon_id'])
            || ! is_string($coupon['code'])
            || ! is_string($coupon['type'])
            || ! is_string($coupon['scope'])
            || ! is_int($coupon['value'])
            || ! is_int($coupon['eligible_subtotal_vnd'])
            || ! mb_check_encoding($coupon['code'], 'UTF-8')
            || ! mb_check_encoding($coupon['type'], 'UTF-8')
            || ! mb_check_encoding($coupon['scope'], 'UTF-8')) {
            throw new InvalidArgumentException('Order coupon snapshot shape is invalid.');
        }

        new CheckoutCouponSnapshot(
            $coupon['coupon_id'],
            $coupon['code'],
            $coupon['type'],
            $coupon['scope'],
            $coupon['value'],
            $coupon['eligible_subtotal_vnd'],
        );
    }

    /** @return list<array{product_id:int, product_name:string, sku:string, quantity:int, unit_price_vnd:int, line_subtotal_vnd:int}> */
    public static function canonicalAttemptItems(mixed $snapshot): array
    {
        if (! is_array($snapshot) || ! array_is_list($snapshot) || $snapshot === []) {
            throw new InvalidArgumentException('Payment Attempt item snapshot must be a non-empty list.');
        }

        $expectedKeys = [
            'brand_id', 'category_id', 'line_subtotal_vnd', 'product_id',
            'product_name', 'quantity', 'sku', 'unit_price_vnd',
        ];
        $lines = [];
        $productIds = [];

        foreach ($snapshot as $line) {
            if (! is_array($line)) {
                throw new InvalidArgumentException('Payment Attempt item snapshot shape is invalid.');
            }

            $actualKeys = array_keys($line);
            sort($actualKeys, SORT_STRING);

            if ($actualKeys !== $expectedKeys
                || ! is_int($line['product_id'])
                || ! is_int($line['category_id'])
                || ! is_int($line['brand_id'])
                || ! is_string($line['sku'])
                || ! is_string($line['product_name'])
                || ! is_int($line['quantity'])
                || ! is_int($line['unit_price_vnd'])
                || ! is_int($line['line_subtotal_vnd'])
                || ! mb_check_encoding($line['sku'], 'UTF-8')
                || ! mb_check_encoding($line['product_name'], 'UTF-8')
                || isset($productIds[$line['product_id']])) {
                throw new InvalidArgumentException('Payment Attempt item snapshot shape is invalid.');
            }

            new CheckoutQuoteLine(
                $line['product_id'],
                $line['category_id'],
                $line['brand_id'],
                $line['sku'],
                $line['product_name'],
                $line['quantity'],
                $line['unit_price_vnd'],
                $line['line_subtotal_vnd'],
            );

            $productIds[$line['product_id']] = true;
            $lines[] = [
                'product_id' => $line['product_id'],
                'product_name' => $line['product_name'],
                'sku' => $line['sku'],
                'quantity' => $line['quantity'],
                'unit_price_vnd' => $line['unit_price_vnd'],
                'line_subtotal_vnd' => $line['line_subtotal_vnd'],
            ];
        }

        usort($lines, fn (array $left, array $right): int => $left['product_id'] <=> $right['product_id']);

        return $lines;
    }

    /** @param array<string, mixed> $shipping */
    public static function assertShippingSnapshot(array $shipping, int $shippingRateId, int $shippingFeeVnd): void
    {
        $expectedKeys = ['region_key', 'region_label', 'shipping_fee_vnd', 'shipping_rate_id'];
        $actualKeys = array_keys($shipping);
        sort($actualKeys, SORT_STRING);

        if ($actualKeys !== $expectedKeys
            || ! is_int($shipping['shipping_rate_id'])
            || ! is_string($shipping['region_key'])
            || ! is_string($shipping['region_label'])
            || ! is_int($shipping['shipping_fee_vnd'])) {
            throw new InvalidArgumentException('Payment Attempt shipping snapshot shape is invalid.');
        }

        $snapshot = new CheckoutShippingSnapshot(
            $shipping['shipping_rate_id'],
            $shipping['region_key'],
            $shipping['region_label'],
            $shipping['shipping_fee_vnd'],
        );

        if ($snapshot->shippingRateId !== $shippingRateId
            || $snapshot->shippingFeeVnd !== $shippingFeeVnd) {
            throw new InvalidArgumentException('Payment Attempt shipping snapshot does not match the Order.');
        }
    }
}
