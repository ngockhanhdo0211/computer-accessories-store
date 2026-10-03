<?php

namespace App\Actions;

use App\Enums\PaymentMethod;
use App\Models\Coupon;
use App\Models\User;
use App\ValueObjects\CheckoutQuote;
use App\ValueObjects\CheckoutQuoteLine;
use Illuminate\Validation\ValidationException;
use JsonException;

class BuildCodOrderFingerprint
{
    public function __construct(private readonly ?AllocateCheckoutDiscounts $discounts = null) {}

    /**
     * @return array{fingerprint:string, discounts:array<int, int>, coupon_snapshot:array<string, mixed>|null}
     */
    public function handle(User $customer, string $requestKey, CheckoutQuote $quote, ?Coupon $coupon): array
    {
        $discounts = ($this->discounts ?? new AllocateCheckoutDiscounts)->handle($quote, $coupon);
        $lines = collect($quote->lines)
            ->sortBy(fn (CheckoutQuoteLine $line): int => $line->productId)
            ->values()
            ->map(fn (CheckoutQuoteLine $line): array => [
                'product_id' => $line->productId,
                'product_name' => $line->productName,
                'sku' => $line->sku,
                'quantity' => $line->quantity,
                'unit_price_vnd' => $line->unitPriceVnd,
                'discount_vnd' => $discounts[$line->productId],
                'line_subtotal_vnd' => $line->lineSubtotalVnd,
                'line_total_vnd' => $line->lineSubtotalVnd - $discounts[$line->productId],
            ])->all();

        $couponDefinition = $this->couponDefinition($quote, $coupon);
        $couponSnapshot = $quote->coupon?->toArray();
        $payload = [
            'customer_id' => (int) $customer->id,
            'request_key' => $requestKey,
            'payment_method' => PaymentMethod::CashOnDelivery->value,
            'recipient' => $quote->recipient->snapshot(),
            'shipping' => $quote->shipping->toArray(),
            'coupon' => $couponDefinition,
            'lines' => $lines,
            'pricing' => [
                'cart_subtotal_vnd' => $quote->cartSubtotalVnd,
                'item_discount_vnd' => $quote->productDiscountVnd,
                'shipping_fee_vnd' => $quote->shippingFeeVnd,
                'shipping_discount_vnd' => $quote->shippingDiscountVnd,
                'total_discount_vnd' => $quote->totalDiscountVnd,
                'total_vnd' => $quote->grandTotalVnd,
            ],
        ];

        try {
            $json = json_encode(
                $this->canonicalize($payload),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
            );
        } catch (JsonException) {
            throw ValidationException::withMessages([
                'cart' => 'Không thể tạo dấu vân tay an toàn cho dữ liệu checkout.',
            ]);
        }

        return [
            'fingerprint' => hash('sha256', $json),
            'discounts' => $discounts,
            'coupon_snapshot' => $couponSnapshot,
        ];
    }

    /** @return array<string, mixed>|null */
    private function couponDefinition(CheckoutQuote $quote, ?Coupon $coupon): ?array
    {
        if ($quote->coupon === null) {
            if ($coupon !== null) {
                throw ValidationException::withMessages(['coupon_code' => 'Snapshot mã giảm giá không khớp báo giá.']);
            }

            return null;
        }

        if ($coupon === null || $quote->coupon->couponId !== $coupon->id) {
            throw ValidationException::withMessages(['coupon_code' => 'Snapshot mã giảm giá không khớp định nghĩa hiện tại.']);
        }

        $targetIds = $coupon->targetIds();
        sort($targetIds, SORT_NUMERIC);

        return [
            'coupon_id' => (int) $coupon->id,
            'code' => mb_strtoupper(trim($coupon->code)),
            'type' => $coupon->type->value,
            'scope' => $coupon->scope->value,
            'value' => $coupon->value,
            'min_subtotal_vnd' => $coupon->min_subtotal_vnd,
            'required_tier' => $coupon->required_tier?->value,
            'max_uses' => $coupon->max_uses,
            'max_uses_per_user' => $coupon->max_uses_per_user,
            'starts_at' => $coupon->starts_at->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'ends_at' => $coupon->ends_at->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'is_active' => $coupon->is_active,
            'target_ids' => $targetIds,
            'eligible_subtotal_vnd' => $quote->coupon->eligibleSubtotalVnd,
        ];
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }
}
