<?php

namespace App\Actions;

use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\CartItem;
use App\Models\PaymentAttempt;
use App\Models\Product;
use App\Models\StockReservation;
use App\Models\User;
use App\ValueObjects\CheckoutRecipient;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;

class CreatePaymentAttempt
{
    public const RESERVATION_MINUTES = 15;

    public function __construct(private readonly BuildCheckoutQuote $quotes) {}

    public function handle(
        User $user,
        CheckoutRecipient $recipient,
        string $requestKey,
        ?string $couponCode = null,
        ?CarbonInterface $at = null,
    ): PaymentAttempt {
        $this->assertActiveCustomer($user);
        $requestKey = trim($requestKey);
        $couponCode = $couponCode === null ? null : trim($couponCode);

        if (! Str::isUuid($requestKey) || strlen($requestKey) !== 36) {
            throw ValidationException::withMessages([
                'request_key' => 'Khóa chống tạo trùng phải là UUID hợp lệ.',
            ]);
        }

        if ($couponCode !== null && $couponCode !== '') {
            throw ValidationException::withMessages([
                'coupon_code' => 'Chưa thể khởi tạo thanh toán VNPay có mã giảm giá cho đến khi Coupon Usage được triển khai.',
            ]);
        }

        $createdAt = CarbonImmutable::instance($at ?? now())->utc();
        $initialProductIds = $this->cartProductIds($user);

        return DB::transaction(function () use ($user, $recipient, $requestKey, $createdAt, $initialProductIds): PaymentAttempt {
            $existing = $this->findExisting($user, $requestKey);
            if ($existing !== null) {
                return $this->replay($existing, $user, $recipient);
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

            $existing = $this->findExisting($user, $requestKey);
            if ($existing !== null) {
                return $this->replay($existing, $user, $recipient);
            }

            $quote = $this->quotes->handle($user, $recipient, null, $createdAt, true);

            if ($quote->coupon !== null) {
                throw ValidationException::withMessages([
                    'coupon_code' => 'Không thể giữ lượt mã giảm giá trong slice thanh toán hiện tại.',
                ]);
            }

            $expiresAt = $createdAt->addMinutes(self::RESERVATION_MINUTES);
            $attempt = new PaymentAttempt;
            $attempt->forceFill([
                'user_id' => $user->id,
                'shipping_rate_id' => $quote->shipping->shippingRateId,
                'request_key' => $requestKey,
                'gateway_reference' => 'PA-'.Str::uuid(),
                'gateway_transaction_id' => null,
                'status' => PaymentStatus::Unpaid,
                'amount_vnd' => $quote->grandTotalVnd,
                'items_snapshot_json' => array_map(
                    fn ($line) => $line->snapshot(),
                    $quote->lines,
                ),
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
                    'quoted_at' => $quote->quotedAt->toIso8601String(),
                ],
                'shipping_fee_vnd' => $quote->shippingFeeVnd,
                'coupon_id' => null,
                'expires_at' => $expiresAt,
                'verified_at' => null,
                'gateway_result_code' => null,
                'late_callback_exception' => false,
            ])->save();

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

            return $attempt->load('stockReservations');
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
        User $user,
        CheckoutRecipient $recipient,
    ): PaymentAttempt {
        $storedLines = $this->storedIdempotencyLines($attempt);

        $currentLines = CartItem::query()
            ->where('user_id', $user->id)
            ->orderBy('product_id')
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['product_id', 'quantity'])
            ->map(fn (CartItem $item) => [
                'product_id' => (int) $item->product_id,
                'quantity' => (int) $item->quantity,
            ])
            ->all();

        try {
            $storedFingerprint = $this->payloadFingerprint([
                'recipient' => $attempt->recipient_snapshot_json,
                'lines' => $storedLines,
            ]);
            $currentFingerprint = $this->payloadFingerprint([
                'recipient' => $recipient->snapshot(),
                'lines' => $currentLines,
            ]);
        } catch (JsonException) {
            $storedFingerprint = null;
            $currentFingerprint = null;
        }

        if ($attempt->coupon_id !== null
            || $storedLines === null
            || $storedFingerprint === null
            || ! hash_equals($storedFingerprint, $currentFingerprint)) {
            throw ValidationException::withMessages([
                'request_key' => 'Khóa chống tạo trùng đã được dùng cho một payload checkout khác.',
            ]);
        }

        return $attempt->loadMissing('stockReservations');
    }

    /** @return list<array{product_id: int, quantity: int}>|null */
    private function storedIdempotencyLines(PaymentAttempt $attempt): ?array
    {
        if (! is_array($attempt->items_snapshot_json)) {
            return null;
        }

        $lines = [];

        foreach ($attempt->items_snapshot_json as $line) {
            if (! is_array($line)
                || ! isset($line['product_id'], $line['quantity'])
                || ! is_int($line['product_id'])
                || ! is_int($line['quantity'])) {
                return null;
            }

            $lines[] = [
                'product_id' => $line['product_id'],
                'quantity' => $line['quantity'],
            ];
        }

        usort($lines, fn (array $left, array $right): int => $left['product_id'] <=> $right['product_id']);

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
}
