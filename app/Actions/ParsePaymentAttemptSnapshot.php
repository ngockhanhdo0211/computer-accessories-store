<?php

namespace App\Actions;

use App\Models\PaymentAttempt;
use App\ValueObjects\OrderSnapshot;
use App\ValueObjects\VnPayOrderSnapshot;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

class ParsePaymentAttemptSnapshot
{
    public function handle(PaymentAttempt $attempt): VnPayOrderSnapshot
    {
        $items = $attempt->items_snapshot_json;
        $recipient = $attempt->recipient_snapshot_json;
        $pricing = $attempt->pricing_snapshot_json;
        if (! is_array($items) || ! array_is_list($items) || $items === []
            || ! is_array($recipient) || ! is_array($pricing)) {
            throw new InvalidArgumentException('Payment Attempt snapshots are malformed.');
        }
        $recipientKeys = array_keys($recipient);
        sort($recipientKeys, SORT_STRING);
        if ($recipientKeys !== ['recipient_address', 'recipient_email', 'recipient_name', 'recipient_phone', 'recipient_region']) {
            throw new InvalidArgumentException('Payment Attempt recipient snapshot shape is invalid.');
        }

        $pricing = $this->pricing($attempt, $pricing);
        $coupon = $pricing['coupon'] ?? null;
        $orderSnapshot = new OrderSnapshot(
            $recipient['recipient_name'] ?? null,
            $recipient['recipient_email'] ?? null,
            $recipient['recipient_phone'] ?? null,
            $recipient['recipient_address'] ?? null,
            $recipient['recipient_region'] ?? null,
            $coupon,
            $pricing['cart_subtotal_vnd'],
            $pricing['item_discount_vnd'],
            $pricing['shipping_fee_vnd'],
            $pricing['shipping_discount_vnd'],
            $pricing['total_vnd'],
        );
        if ($attempt->amount_vnd !== $orderSnapshot->totalVnd
            || $attempt->shipping_fee_vnd !== $orderSnapshot->shippingFeeVnd
            || (($attempt->coupon_id === null) !== ($coupon === null))
            || ($coupon !== null && $coupon['coupon_id'] !== $attempt->coupon_id)) {
            throw new InvalidArgumentException('Payment Attempt snapshot references do not reconcile.');
        }

        OrderSnapshot::assertShippingSnapshot(
            $pricing['shipping'],
            (int) $attempt->shipping_rate_id,
            $orderSnapshot->shippingFeeVnd,
        );

        $oldKeys = ['brand_id', 'category_id', 'line_subtotal_vnd', 'product_id', 'product_name', 'quantity', 'sku', 'unit_price_vnd'];
        $newKeys = ['brand_id', 'cart_item_id', 'cart_item_updated_at', 'category_id', 'discount_vnd', 'line_total_vnd', 'product_id', 'product_name', 'quantity', 'sku', 'subtotal_vnd', 'unit_price_vnd'];
        $lines = [];
        $productIds = [];
        $cartItemIds = [];
        $shape = null;
        $subtotalSum = 0;
        $discountSum = 0;
        $totalSum = 0;

        foreach ($items as $line) {
            if (! is_array($line)) {
                throw new InvalidArgumentException('Payment Attempt item snapshot is malformed.');
            }
            $keys = array_keys($line);
            sort($keys, SORT_STRING);
            $lineShape = $keys === $newKeys ? 'new' : ($keys === $oldKeys ? 'old' : null);
            if ($lineShape === null || ($shape !== null && $shape !== $lineShape)) {
                throw new InvalidArgumentException('Payment Attempt item snapshot shape is invalid.');
            }
            $shape = $lineShape;

            foreach (['product_id', 'category_id', 'brand_id', 'quantity', 'unit_price_vnd'] as $integer) {
                if (! is_int($line[$integer])) {
                    throw new InvalidArgumentException('Payment Attempt item snapshot types are invalid.');
                }
            }
            if (! is_string($line['sku']) || ! is_string($line['product_name'])
                || ! mb_check_encoding($line['sku'], 'UTF-8') || ! mb_check_encoding($line['product_name'], 'UTF-8')
                || trim($line['sku']) === '' || trim($line['product_name']) === ''
                || $line['product_id'] < 1 || $line['category_id'] < 1 || $line['brand_id'] < 1
                || $line['quantity'] < 1 || $line['unit_price_vnd'] < 0
                || ($line['unit_price_vnd'] !== 0 && $line['quantity'] > intdiv(PHP_INT_MAX, $line['unit_price_vnd']))) {
                throw new InvalidArgumentException('Payment Attempt item snapshot values are invalid.');
            }

            $subtotal = $lineShape === 'new' ? $line['subtotal_vnd'] : $line['line_subtotal_vnd'];
            if (! is_int($subtotal) || $subtotal !== $line['quantity'] * $line['unit_price_vnd']
                || isset($productIds[$line['product_id']])) {
                throw new InvalidArgumentException('Payment Attempt item subtotal is invalid.');
            }

            $discount = null;
            $lineTotal = null;
            $cartItemId = null;
            $cartItemUpdatedAt = null;
            if ($lineShape === 'new') {
                if (! is_int($line['discount_vnd']) || ! is_int($line['line_total_vnd'])
                    || ! is_int($line['cart_item_id']) || ! is_string($line['cart_item_updated_at'])
                    || $line['cart_item_id'] < 1
                    || ! $this->isUtcMicrosecondTimestamp($line['cart_item_updated_at'])
                    || $line['discount_vnd'] < 0 || $line['discount_vnd'] > $subtotal
                    || $line['line_total_vnd'] !== $subtotal - $line['discount_vnd']) {
                    throw new InvalidArgumentException('Payment Attempt line pricing is invalid.');
                }
                $discount = $line['discount_vnd'];
                $lineTotal = $line['line_total_vnd'];
                $cartItemId = $line['cart_item_id'];
                $cartItemUpdatedAt = $line['cart_item_updated_at'];
                if (isset($cartItemIds[$cartItemId])) {
                    throw new InvalidArgumentException('Payment Attempt Cart identity is duplicated.');
                }
                $cartItemIds[$cartItemId] = true;
            }

            if ($subtotal > PHP_INT_MAX - $subtotalSum) {
                throw new InvalidArgumentException('Payment Attempt item totals overflow.');
            }
            $subtotalSum += $subtotal;
            if ($discount !== null) {
                if ($discount > PHP_INT_MAX - $discountSum || $lineTotal > PHP_INT_MAX - $totalSum) {
                    throw new InvalidArgumentException('Payment Attempt item totals overflow.');
                }
                $discountSum += $discount;
                $totalSum += $lineTotal;
            }

            $productIds[$line['product_id']] = true;
            $lines[] = [
                'product_id' => $line['product_id'], 'product_name' => $line['product_name'], 'sku' => $line['sku'],
                'quantity' => $line['quantity'], 'unit_price_vnd' => $line['unit_price_vnd'],
                'line_subtotal_vnd' => $subtotal, 'discount_vnd' => $discount, 'line_total_vnd' => $lineTotal,
                'cart_item_id' => $cartItemId, 'cart_item_updated_at' => $cartItemUpdatedAt,
            ];
        }

        usort($lines, fn (array $left, array $right): int => $left['product_id'] <=> $right['product_id']);
        if ($subtotalSum !== $pricing['cart_subtotal_vnd']) {
            throw new InvalidArgumentException('Payment Attempt item subtotal does not match pricing.');
        }

        $incomplete = false;
        if ($shape === 'new') {
            if ($discountSum !== $pricing['item_discount_vnd']
                || $totalSum !== $pricing['cart_subtotal_vnd'] - $pricing['item_discount_vnd']) {
                throw new InvalidArgumentException('Payment Attempt line discounts do not match pricing.');
            }
        } elseif ($pricing['item_discount_vnd'] === 0) {
            foreach ($lines as &$line) {
                $line['discount_vnd'] = 0;
                $line['line_total_vnd'] = $line['line_subtotal_vnd'];
            }
            unset($line);
        } elseif (count($lines) === 1 && $pricing['item_discount_vnd'] <= $lines[0]['line_subtotal_vnd']) {
            $lines[0]['discount_vnd'] = $pricing['item_discount_vnd'];
            $lines[0]['line_total_vnd'] = $lines[0]['line_subtotal_vnd'] - $pricing['item_discount_vnd'];
        } else {
            $incomplete = true;
        }

        return new VnPayOrderSnapshot($lines, $recipient, $pricing, $coupon, $shape === 'old', $incomplete);
    }

    private function isUtcMicrosecondTimestamp(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/D', $value) !== 1) {
            return false;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();

        return $date !== false
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $date->format('Y-m-d\TH:i:s.u\Z') === $value;
    }

    /** @param array<string, mixed> $pricing */
    private function pricing(PaymentAttempt $attempt, array $pricing): array
    {
        $required = [
            'cart_subtotal_vnd', 'item_discount_vnd', 'shipping_fee_vnd', 'shipping_discount_vnd',
            'shipping_fee_after_discount_vnd', 'total_discount_vnd', 'total_vnd', 'shipping',
        ];
        $allowed = array_merge($required, ['coupon', 'quoted_at']);
        foreach ($required as $key) {
            if (! array_key_exists($key, $pricing)) {
                throw new InvalidArgumentException('Payment Attempt pricing snapshot is incomplete.');
            }
        }
        if (array_diff(array_keys($pricing), $allowed) !== [] || ! is_array($pricing['shipping'])) {
            throw new InvalidArgumentException('Payment Attempt pricing snapshot shape is invalid.');
        }
        foreach (array_slice($required, 0, 7) as $key) {
            if (! is_int($pricing[$key]) || $pricing[$key] < 0) {
                throw new InvalidArgumentException('Payment Attempt pricing types are invalid.');
            }
        }
        if (($pricing['coupon'] ?? null) !== null && ! is_array($pricing['coupon'])) {
            throw new InvalidArgumentException('Payment Attempt coupon snapshot is invalid.');
        }
        if ($pricing['item_discount_vnd'] > $pricing['cart_subtotal_vnd']
            || $pricing['shipping_discount_vnd'] > $pricing['shipping_fee_vnd']
            || $pricing['item_discount_vnd'] > PHP_INT_MAX - $pricing['shipping_discount_vnd']) {
            throw new InvalidArgumentException('Payment Attempt pricing snapshot overflows.');
        }
        $itemsAfterDiscount = $pricing['cart_subtotal_vnd'] - $pricing['item_discount_vnd'];
        $shippingAfterDiscount = $pricing['shipping_fee_vnd'] - $pricing['shipping_discount_vnd'];
        if ($shippingAfterDiscount > PHP_INT_MAX - $itemsAfterDiscount
            || $pricing['shipping_fee_after_discount_vnd'] !== $shippingAfterDiscount
            || $pricing['total_discount_vnd'] !== $pricing['item_discount_vnd'] + $pricing['shipping_discount_vnd']
            || $pricing['total_vnd'] !== $itemsAfterDiscount + $shippingAfterDiscount
            || $pricing['shipping_fee_vnd'] !== $attempt->shipping_fee_vnd
            || $pricing['total_vnd'] !== $attempt->amount_vnd) {
            throw new InvalidArgumentException('Payment Attempt pricing snapshot does not reconcile.');
        }

        return $pricing;
    }
}
