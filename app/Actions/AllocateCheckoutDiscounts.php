<?php

namespace App\Actions;

use App\Enums\CouponScope;
use App\Models\Coupon;
use App\ValueObjects\CheckoutQuote;
use App\ValueObjects\CheckoutQuoteLine;
use Illuminate\Validation\ValidationException;

class AllocateCheckoutDiscounts
{
    /** @return array<int, int> */
    public function handle(CheckoutQuote $quote, ?Coupon $coupon): array
    {
        $lines = collect($quote->lines)
            ->sortBy(fn (CheckoutQuoteLine $line): int => $line->productId)
            ->values();
        $discounts = $lines
            ->mapWithKeys(fn (CheckoutQuoteLine $line): array => [$line->productId => 0])
            ->all();

        if ($quote->productDiscountVnd === 0) {
            return $discounts;
        }

        if ($coupon === null || $quote->coupon?->couponId !== $coupon->id) {
            throw ValidationException::withMessages([
                'coupon_code' => 'Snapshot mã giảm giá không khớp định nghĩa hiện tại.',
            ]);
        }

        $targets = array_fill_keys($coupon->targetIds(), true);
        $eligible = $lines->filter(function (CheckoutQuoteLine $line) use ($coupon, $targets): bool {
            return match ($coupon->scope) {
                CouponScope::Cart => true,
                CouponScope::Product => isset($targets[$line->productId]),
                CouponScope::Category => isset($targets[$line->categoryId]),
                CouponScope::Brand => isset($targets[$line->brandId]),
            };
        })->values();

        $eligibleSubtotal = 0;
        foreach ($eligible as $line) {
            if ($line->lineSubtotalVnd > PHP_INT_MAX - $eligibleSubtotal) {
                throw ValidationException::withMessages(['cart' => 'Tổng tiền đủ điều kiện vượt giới hạn số nguyên.']);
            }
            $eligibleSubtotal += $line->lineSubtotalVnd;
        }

        if ($quote->coupon === null || $eligibleSubtotal !== $quote->coupon->eligibleSubtotalVnd
            || $eligibleSubtotal < 1 || $quote->productDiscountVnd > $eligibleSubtotal) {
            throw ValidationException::withMessages([
                'coupon_code' => 'Phạm vi mã giảm giá vừa thay đổi. Vui lòng tạo lại báo giá.',
            ]);
        }

        $remainders = [];
        $allocated = 0;
        foreach ($eligible as $line) {
            [$base, $remainder] = $this->multiplyAndDivide(
                $quote->productDiscountVnd,
                $line->lineSubtotalVnd,
                $eligibleSubtotal,
            );
            if ($base > $line->lineSubtotalVnd || $base > PHP_INT_MAX - $allocated) {
                throw ValidationException::withMessages(['cart' => 'Phân bổ giảm giá vượt giới hạn an toàn.']);
            }
            $discounts[$line->productId] = $base;
            $allocated += $base;
            $remainders[] = ['product_id' => $line->productId, 'remainder' => $remainder];
        }

        $remaining = $quote->productDiscountVnd - $allocated;
        usort($remainders, fn (array $left, array $right): int => $right['remainder'] <=> $left['remainder']
            ?: $left['product_id'] <=> $right['product_id']);

        if ($remaining < 0 || $remaining > count($remainders)) {
            throw ValidationException::withMessages(['cart' => 'Phân bổ giảm giá không thể đối soát.']);
        }

        for ($index = 0; $index < $remaining; $index++) {
            $productId = $remainders[$index]['product_id'];
            $discounts[$productId]++;
        }

        if (array_sum($discounts) !== $quote->productDiscountVnd) {
            throw ValidationException::withMessages(['cart' => 'Phân bổ giảm giá không khớp tổng giảm giá.']);
        }

        return $discounts;
    }

    /** @return array{int, int} */
    private function multiplyAndDivide(int $left, int $right, int $divisor): array
    {
        if ($left < 0 || $right < 0 || $divisor < 1) {
            throw ValidationException::withMessages(['cart' => 'Không thể phân bổ giảm giá từ giá trị không hợp lệ.']);
        }

        $quotient = 0;
        $remainder = 0;
        $addQuotient = intdiv($right, $divisor);
        $addRemainder = $right % $divisor;

        while ($left > 0) {
            if (($left & 1) === 1) {
                [$quotient, $remainder] = $this->addFraction(
                    $quotient, $remainder, $addQuotient, $addRemainder, $divisor,
                );
            }

            $left = intdiv($left, 2);
            if ($left > 0) {
                [$addQuotient, $addRemainder] = $this->addFraction(
                    $addQuotient, $addRemainder, $addQuotient, $addRemainder, $divisor,
                );
            }
        }

        return [$quotient, $remainder];
    }

    /** @return array{int, int} */
    private function addFraction(int $quotient, int $remainder, int $otherQuotient, int $otherRemainder, int $divisor): array
    {
        if ($otherQuotient > PHP_INT_MAX - $quotient) {
            throw ValidationException::withMessages(['cart' => 'Phân bổ giảm giá vượt giới hạn số nguyên.']);
        }
        $quotient += $otherQuotient;

        if ($remainder >= $divisor - $otherRemainder) {
            if ($quotient === PHP_INT_MAX) {
                throw ValidationException::withMessages(['cart' => 'Phân bổ giảm giá vượt giới hạn số nguyên.']);
            }

            return [$quotient + 1, $remainder - ($divisor - $otherRemainder)];
        }

        return [$quotient, $remainder + $otherRemainder];
    }
}
