<?php

namespace Tests\Unit;

use App\Enums\ShippingRegion;
use App\ValueObjects\CheckoutQuote;
use App\ValueObjects\CheckoutQuoteLine;
use App\ValueObjects\CheckoutRecipient;
use App\ValueObjects\CheckoutShippingSnapshot;
use Carbon\CarbonImmutable;
use Error;
use InvalidArgumentException;
use Tests\TestCase;

class CheckoutQuoteValueObjectsTest extends TestCase
{
    public function test_quote_snapshots_are_readonly_scalar_data_and_reconcile(): void
    {
        $recipient = new CheckoutRecipient(
            'Nguyen Minh Anh',
            'receiver@example.test',
            '0912345678',
            'Ha Noi',
            'Cau Giay',
            'Dich Vong',
            '12 Tran Thai Tong',
        );
        $shipping = new CheckoutShippingSnapshot(1, 'ha_noi', ShippingRegion::HaNoi->label(), 30_000);
        $line = new CheckoutQuoteLine(1, 2, 3, 'SKU-001', 'Keyboard', 2, 100_000, 200_000);
        $quote = new CheckoutQuote(
            $recipient,
            $shipping,
            [$line],
            null,
            200_000,
            0,
            30_000,
            0,
            30_000,
            0,
            230_000,
            CarbonImmutable::parse('2026-09-28 12:00:00'),
        );

        $this->assertSame(200_000, array_sum(array_map(
            fn (CheckoutQuoteLine $quoteLine): int => $quoteLine->lineSubtotalVnd,
            $quote->lines,
        )));
        $this->assertSame(
            $quote->cartSubtotalVnd - $quote->productDiscountVnd
                + $quote->shippingFeeVnd - $quote->shippingDiscountVnd,
            $quote->grandTotalVnd,
        );
        $this->assertContainsOnly('scalar', $recipient->snapshot());
        $this->assertContainsOnly('scalar', $shipping->toArray());

        try {
            $quote->lines[] = $line;
            $this->fail('Readonly quote lines were mutable.');
        } catch (Error) {
            $this->assertCount(1, $quote->lines);
        }
    }

    public function test_line_rejects_a_subtotal_that_does_not_match_price_and_quantity(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CheckoutQuoteLine(1, 2, 3, 'SKU-001', 'Keyboard', 2, 100_000, 199_999);
    }

    public function test_quote_rejects_totals_that_do_not_reconcile_with_lines(): void
    {
        $recipient = new CheckoutRecipient('A', 'a@example.test', '0912345678', 'Ha Noi', 'A', 'B', 'C');
        $shipping = new CheckoutShippingSnapshot(1, 'ha_noi', ShippingRegion::HaNoi->label(), 30_000);
        $line = new CheckoutQuoteLine(1, 2, 3, 'SKU-001', 'Keyboard', 1, 100_000, 100_000);

        $this->expectException(InvalidArgumentException::class);

        new CheckoutQuote(
            $recipient,
            $shipping,
            [$line],
            null,
            99_999,
            0,
            30_000,
            0,
            30_000,
            0,
            129_999,
            CarbonImmutable::now(),
        );
    }
}
