<?php

namespace App\ValueObjects;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class CheckoutQuote
{
    /**
     * @param  list<CheckoutQuoteLine>  $lines
     */
    public function __construct(
        public CheckoutRecipient $recipient,
        public CheckoutShippingSnapshot $shipping,
        public array $lines,
        public ?CheckoutCouponSnapshot $coupon,
        public int $cartSubtotalVnd,
        public int $productDiscountVnd,
        public int $shippingFeeVnd,
        public int $shippingDiscountVnd,
        public int $shippingFeeAfterDiscountVnd,
        public int $totalDiscountVnd,
        public int $grandTotalVnd,
        public CarbonImmutable $quotedAt,
    ) {
        foreach ([
            $cartSubtotalVnd,
            $productDiscountVnd,
            $shippingFeeVnd,
            $shippingDiscountVnd,
            $shippingFeeAfterDiscountVnd,
            $totalDiscountVnd,
            $grandTotalVnd,
        ] as $money) {
            if ($money < 0) {
                throw new InvalidArgumentException('Checkout quote money values must be non-negative integers.');
            }
        }

        if ($lines === []) {
            throw new InvalidArgumentException('Checkout quote must contain at least one line.');
        }

        $lineSubtotal = 0;
        foreach ($lines as $line) {
            if (! $line instanceof CheckoutQuoteLine
                || $line->lineSubtotalVnd > PHP_INT_MAX - $lineSubtotal) {
                throw new InvalidArgumentException('Checkout quote lines are invalid or exceed the integer range.');
            }
            $lineSubtotal += $line->lineSubtotalVnd;
        }

        if ($lineSubtotal !== $cartSubtotalVnd
            || $shipping->shippingFeeVnd !== $shippingFeeVnd
            || ($coupon === null && ($productDiscountVnd !== 0 || $shippingDiscountVnd !== 0))
            || ($coupon !== null && $productDiscountVnd > $coupon->eligibleSubtotalVnd)
            || $productDiscountVnd > PHP_INT_MAX - $shippingDiscountVnd) {
            throw new InvalidArgumentException('Checkout quote snapshots do not reconcile with their totals.');
        }

        if ($productDiscountVnd > $cartSubtotalVnd
            || $shippingDiscountVnd > $shippingFeeVnd
            || $shippingFeeAfterDiscountVnd !== $shippingFeeVnd - $shippingDiscountVnd
            || $totalDiscountVnd !== $productDiscountVnd + $shippingDiscountVnd) {
            throw new InvalidArgumentException('Checkout quote totals do not satisfy the pricing invariant.');
        }

        $itemsAfterDiscount = $cartSubtotalVnd - $productDiscountVnd;
        if ($shippingFeeAfterDiscountVnd > PHP_INT_MAX - $itemsAfterDiscount) {
            throw new InvalidArgumentException('Checkout quote grand total exceeds the integer range.');
        }

        if ($grandTotalVnd !== $itemsAfterDiscount + $shippingFeeAfterDiscountVnd) {
            throw new InvalidArgumentException('Checkout quote totals do not satisfy the pricing invariant.');
        }
    }
}
