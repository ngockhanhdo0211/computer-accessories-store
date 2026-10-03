<?php

namespace App\Actions;

use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\PaymentAttempt;
use App\Models\Product;
use App\Models\StockReservation;
use App\Models\User;
use App\ValueObjects\CheckoutQuote;
use App\ValueObjects\CheckoutRecipient;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;

class CreatePaymentAttempt
{
    public const RESERVATION_MINUTES = 15;

    public function __construct(
        private readonly BuildCheckoutQuote $quotes,
        private readonly ReserveCouponUsage $couponUsages,
        private readonly AllocateCheckoutDiscounts $discounts,
    ) {}

    public function handle(
        User $user,
        CheckoutRecipient $recipient,
        string $requestKey,
        ?string $couponCode = null,
        ?CarbonInterface $at = null,
        ?string $initiatedIpAddress = null,
    ): PaymentAttempt {
        $this->assertActiveCustomer($user);
        $requestKey = trim($requestKey);
        $couponCode = $couponCode === null ? null : Str::upper(trim($couponCode));
        $couponCode = $couponCode === '' ? null : $couponCode;

        if (! Str::isUuid($requestKey) || strlen($requestKey) !== 36) {
            throw ValidationException::withMessages([
                'request_key' => 'Khóa chống tạo trùng phải là UUID hợp lệ.',
            ]);
        }

        $createdAt = CarbonImmutable::instance($at ?? now())->utc();
        $initialProductIds = $this->cartProductIds($user);

        return DB::transaction(function () use ($user, $recipient, $requestKey, $couponCode, $createdAt, $initialProductIds, $initiatedIpAddress): PaymentAttempt {
            $existing = $this->findExisting($user, $requestKey);

            $coupon = $couponCode === null ? null : Coupon::query()
                ->where('code', $couponCode)->lockForUpdate()->first();
            if ($couponCode !== null && $coupon === null) {
                throw ValidationException::withMessages(['coupon_code' => 'Mã giảm giá không tồn tại.']);
            }

            Product::query()
                ->whereKey($initialProductIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id']);

            $lockedItems = CartItem::query()
                ->where('user_id', $user->id)
                ->orderBy('product_id')
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'product_id', 'quantity']);

            $lockedProductIds = $lockedItems->pluck('product_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->sort()
                ->values()
                ->all();

            if ($initialProductIds !== $lockedProductIds) {
                throw ValidationException::withMessages([
                    'cart' => 'Giỏ hàng vừa thay đổi. Vui lòng kiểm tra lại trước khi khởi tạo thanh toán.',
                ]);
            }

            if ($existing === null) {
                $existing = $this->findExisting($user, $requestKey);
            }

            $quote = $this->quotes->handle($user, $recipient, $couponCode, $createdAt, true);
            $lineDiscounts = $this->discounts->handle($quote, $coupon);
            $lineSnapshots = $this->attemptLineSnapshots($quote, $lineDiscounts);

            if ($existing !== null) {
                return $this->replay($existing, $recipient, $quote, $lineSnapshots);
            }

            if ($coupon !== null) {
                $this->couponUsages->assertCapacityLocked($coupon, $user, $createdAt);
            }

            $expiresAt = $createdAt->addMinutes(self::RESERVATION_MINUTES);
            $attemptAttributes = [
                'user_id' => $user->id,
                'shipping_rate_id' => $quote->shipping->shippingRateId,
                'request_key' => $requestKey,
                'initiated_ip_address' => $initiatedIpAddress,
                'gateway_transaction_id' => null,
                'status' => PaymentStatus::Unpaid,
                'amount_vnd' => $quote->grandTotalVnd,
                'items_snapshot_json' => $lineSnapshots,
                'recipient_snapshot_json' => $quote->recipient->snapshot(),
                'pricing_snapshot_json' => [
                    'cart_subtotal_vnd' => $quote->cartSubtotalVnd,
                    'item_discount_vnd' => $quote->productDiscountVnd,
                    'shipping_fee_vnd' => $quote->shippingFeeVnd,
                    'shipping_discount_vnd' => $quote->shippingDiscountVnd,
                    'shipping_fee_after_discount_vnd' => $quote->shippingFeeAfterDiscountVnd,
                    'total_discount_vnd' => $quote->totalDiscountVnd,
                    'total_vnd' => $quote->grandTotalVnd,
                    'shipping' => $quote->shipping->toArray(),
                    'coupon' => $quote->coupon?->toArray(),
                    'quoted_at' => $quote->quotedAt->toIso8601String(),
                ],
                'shipping_fee_vnd' => $quote->shippingFeeVnd,
                'coupon_id' => $quote->coupon?->couponId,
                'expires_at' => $expiresAt,
                'verified_at' => null,
                'gateway_result_code' => null,
                'late_callback_exception' => false,
            ];

            for ($referenceAttempt = 0; ; $referenceAttempt++) {
                $attempt = new PaymentAttempt;
                $attempt->forceFill(array_merge($attemptAttributes, [
                    'gateway_reference' => 'PA'.strtoupper(str_replace('-', '', (string) Str::uuid())),
                ]));

                try {
                    $attempt->save();
                    break;
                } catch (QueryException $exception) {
                    if ($referenceAttempt >= 2 || ! $this->isGatewayReferenceDuplicate($exception)) {
                        throw $exception;
                    }
                }
            }

            foreach ($quote->lines as $line) {
                $reservation = new StockReservation;
                $reservation->forceFill([
                    'payment_attempt_id' => $attempt->id,
                    'product_id' => $line->productId,
                    'quantity' => $line->quantity,
                    'expires_at' => $expiresAt,
                    'released_at' => null,
                    'consumed_at' => null,
                ])->save();
            }

            if ($coupon !== null) {
                $this->couponUsages->reserveLocked($coupon, $user, $attempt, $quote, $expiresAt, $createdAt, true);
            }

            return $attempt->load(['stockReservations', 'couponUsage']);
        }, 3);
    }

    private function findExisting(User $user, string $requestKey): ?PaymentAttempt
    {
        return PaymentAttempt::query()
            ->where('user_id', $user->id)
            ->where('request_key', $requestKey)
            ->lockForUpdate()
            ->first();
    }

    private function assertActiveCustomer(User $user): void
    {
        if (UserRole::tryFrom((string) $user->getRawOriginal('role')) !== UserRole::Customer
            || UserStatus::tryFrom((string) $user->getRawOriginal('status')) !== UserStatus::Active) {
            throw new AuthorizationException('Only active customers can create Payment Attempts.');
        }
    }

    private function replay(
        PaymentAttempt $attempt,
        CheckoutRecipient $recipient,
        CheckoutQuote $quote,
        array $lineSnapshots,
    ): PaymentAttempt {
        $storedPayload = $this->storedReplayPayload($attempt);
        $currentPayload = [
            'recipient' => $recipient->snapshot(),
            'lines' => $this->sortLines($lineSnapshots),
            'pricing' => $this->pricingSnapshot($quote),
        ];

        try {
            $storedFingerprint = $storedPayload === null ? null : $this->payloadFingerprint($storedPayload);
            $currentFingerprint = $this->payloadFingerprint($currentPayload);
        } catch (JsonException) {
            $storedFingerprint = null;
            $currentFingerprint = null;
        }

        if ($storedFingerprint === null
            || ! hash_equals($storedFingerprint, $currentFingerprint)) {
            throw ValidationException::withMessages([
                'request_key' => 'Khóa chống tạo trùng đã được dùng cho một payload checkout khác.',
            ]);
        }

        return $attempt->loadMissing(['stockReservations', 'couponUsage']);
    }

    private function storedReplayPayload(PaymentAttempt $attempt): ?array
    {
        if (! is_array($attempt->items_snapshot_json)
            || ! is_array($attempt->recipient_snapshot_json)
            || ! is_array($attempt->pricing_snapshot_json)) {
            return null;
        }

        foreach ($attempt->items_snapshot_json as $line) {
            if (! is_array($line)
                || ! isset($line['product_id'], $line['quantity'], $line['unit_price_vnd'])
                || ! is_int($line['product_id'])
                || ! is_int($line['quantity'])
                || ! is_int($line['unit_price_vnd'])
                || (! is_int($line['subtotal_vnd'] ?? null)
                    && ! is_int($line['line_subtotal_vnd'] ?? null))) {
                return null;
            }
        }

        $pricing = $attempt->pricing_snapshot_json;
        unset($pricing['quoted_at']);

        return [
            'recipient' => $attempt->recipient_snapshot_json,
            'lines' => $this->sortLines($attempt->items_snapshot_json),
            'pricing' => $pricing,
        ];
    }

    private function pricingSnapshot(CheckoutQuote $quote): array
    {
        return [
            'cart_subtotal_vnd' => $quote->cartSubtotalVnd,
            'item_discount_vnd' => $quote->productDiscountVnd,
            'shipping_fee_vnd' => $quote->shippingFeeVnd,
            'shipping_discount_vnd' => $quote->shippingDiscountVnd,
            'shipping_fee_after_discount_vnd' => $quote->shippingFeeAfterDiscountVnd,
            'total_discount_vnd' => $quote->totalDiscountVnd,
            'total_vnd' => $quote->grandTotalVnd,
            'shipping' => $quote->shipping->toArray(),
            'coupon' => $quote->coupon?->toArray(),
        ];
    }

    private function sortLines(array $lines): array
    {
        usort($lines, fn (array $left, array $right): int => $left['product_id'] <=> $right['product_id']);

        return $lines;
    }

    /**
     * @param  array<int, int>  $discounts
     * @return list<array<string, int|string>>
     */
    private function attemptLineSnapshots(CheckoutQuote $quote, array $discounts): array
    {
        $lines = collect($quote->lines)
            ->sortBy(fn ($line): int => $line->productId)
            ->values()
            ->map(fn ($line): array => $line->paymentAttemptSnapshot($discounts[$line->productId] ?? -1))
            ->all();

        $subtotal = 0;
        $discount = 0;
        $total = 0;
        foreach ($lines as $line) {
            if ($line['subtotal_vnd'] > PHP_INT_MAX - $subtotal
                || $line['discount_vnd'] > PHP_INT_MAX - $discount
                || $line['line_total_vnd'] > PHP_INT_MAX - $total) {
                throw ValidationException::withMessages(['cart' => 'Snapshot dòng thanh toán vượt giới hạn số nguyên.']);
            }
            $subtotal += $line['subtotal_vnd'];
            $discount += $line['discount_vnd'];
            $total += $line['line_total_vnd'];
        }

        if ($subtotal !== $quote->cartSubtotalVnd
            || $discount !== $quote->productDiscountVnd
            || $total !== $quote->cartSubtotalVnd - $quote->productDiscountVnd) {
            throw ValidationException::withMessages(['cart' => 'Snapshot dòng thanh toán không khớp tổng báo giá.']);
        }

        return $lines;
    }

    /** @throws JsonException */
    private function payloadFingerprint(array $payload): string
    {
        return hash('sha256', json_encode(
            $this->canonicalize($payload),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        ));
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

    /** @return list<int> */
    private function cartProductIds(User $user): array
    {
        return CartItem::query()
            ->where('user_id', $user->id)
            ->orderBy('product_id')
            ->pluck('product_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function isGatewayReferenceDuplicate(QueryException $exception): bool
    {
        $message = mb_strtolower($exception->getMessage());

        return str_contains($message, 'payment_attempts_gateway_reference_unique')
            || str_contains($message, 'payment_attempts.gateway_reference');
    }
}
