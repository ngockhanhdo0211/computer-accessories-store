<?php

namespace Tests\Unit;

use App\Actions\BuildCodOrderFingerprint;
use App\Models\User;
use App\ValueObjects\CheckoutQuote;
use App\ValueObjects\CheckoutQuoteLine;
use App\ValueObjects\CheckoutRecipient;
use App\ValueObjects\CheckoutShippingSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BuildCodOrderFingerprintTest extends TestCase
{
    public function test_fingerprint_is_independent_of_line_order_and_keeps_scalar_types_distinct(): void
    {
        $action = new BuildCodOrderFingerprint;
        $customer = new User;
        $customer->forceFill(['id' => 7]);
        $key = '550e8400-e29b-41d4-a716-446655440000';
        $first = $this->quote([
            new CheckoutQuoteLine(2, 1, 1, 'B', 'Mouse', 1, 20, 20),
            new CheckoutQuoteLine(1, 1, 1, 'A', 'Keyboard', 2, 10, 20),
        ]);
        $second = $this->quote(array_reverse($first->lines));

        $firstResult = $action->handle($customer, $key, $first, null);
        $secondResult = $action->handle($customer, $key, $second, null);

        $this->assertSame($firstResult['fingerprint'], $secondResult['fingerprint']);
        $this->assertSame([1 => 0, 2 => 0], $firstResult['discounts']);

        $otherCustomer = new User;
        $otherCustomer->forceFill(['id' => 8]);
        $this->assertNotSame(
            $firstResult['fingerprint'],
            $action->handle($otherCustomer, $key, $first, null)['fingerprint'],
        );
    }

    public function test_canonical_key_order_is_stable_while_scalar_types_remain_distinct(): void
    {
        $action = new BuildCodOrderFingerprint;
        $canonicalize = new \ReflectionMethod($action, 'canonicalize');
        $fingerprint = fn (array $payload): string => hash('sha256', json_encode(
            $canonicalize->invoke($action, $payload),
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
        ));

        $this->assertSame(
            $fingerprint(['nested' => ['b' => 2, 'a' => 1], 'value' => 7]),
            $fingerprint(['value' => 7, 'nested' => ['a' => 1, 'b' => 2]]),
        );
        $this->assertNotSame($fingerprint(['value' => 7]), $fingerprint(['value' => '7']));
        $this->assertNotSame($fingerprint(['value' => 1]), $fingerprint(['value' => true]));
    }

    public function test_invalid_utf8_is_rejected_instead_of_being_silently_normalized(): void
    {
        $action = new BuildCodOrderFingerprint;
        $customer = new User;
        $customer->forceFill(['id' => 7]);
        $quote = $this->quote([
            new CheckoutQuoteLine(1, 1, 1, 'SKU', "Invalid\xB1", 1, 10, 10),
        ]);

        $this->expectException(ValidationException::class);
        $action->handle($customer, '550e8400-e29b-41d4-a716-446655440000', $quote, null);
    }

    /** @param list<CheckoutQuoteLine> $lines */
    private function quote(array $lines): CheckoutQuote
    {
        $subtotal = array_sum(array_map(fn (CheckoutQuoteLine $line): int => $line->lineSubtotalVnd, $lines));
        $recipient = new CheckoutRecipient(
            'Nguyen Minh Anh',
            'receiver@example.test',
            '0912345678',
            'Ha Noi',
            'Cau Giay',
            'Dich Vong',
            '12 Tran Thai Tong',
        );
        $shipping = new CheckoutShippingSnapshot(1, 'ha_noi', 'Hà Nội', 30_000);

        return new CheckoutQuote(
            $recipient,
            $shipping,
            $lines,
            null,
            $subtotal,
            0,
            30_000,
            0,
            30_000,
            0,
            $subtotal + 30_000,
            CarbonImmutable::parse('2026-09-30 00:00:00 UTC'),
        );
    }
}
