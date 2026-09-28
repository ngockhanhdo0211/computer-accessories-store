<?php

namespace App\Actions;

use App\Enums\MembershipLevel;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\ShippingRateUnavailable;
use App\Models\Coupon;
use App\Models\User;
use App\ValueObjects\CheckoutCouponSnapshot;
use App\ValueObjects\CheckoutQuote;
use App\ValueObjects\CheckoutQuoteLine;
use App\ValueObjects\CheckoutRecipient;
use App\ValueObjects\CheckoutShippingSnapshot;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use OverflowException;
use Throwable;

class BuildCheckoutQuote
{
    public function __construct(
        private readonly CalculateAvailableStock $availability,
        private readonly CalculateShippingRate $shippingRates,
        private readonly ResolveShippingRegion $shippingRegions,
        private readonly EvaluateCoupon $coupons,
    ) {}

    public function handle(
        User $user,
        CheckoutRecipient $recipient,
        ?string $couponCode = null,
        ?CarbonInterface $at = null,
    ): CheckoutQuote {
        $this->assertActiveCustomer($user);
        $quotedAt = CarbonImmutable::instance($at ?? now());

        $cartItems = $user->cartItems()
            ->with([
                'product.category:id,name,is_visible',
                'product.brand:id,name,is_visible',
            ])
            ->orderBy('id')
            ->get();

        if ($cartItems->isEmpty()) {
            throw ValidationException::withMessages([
                'cart' => 'Giỏ hàng đang trống. Hãy thêm sản phẩm trước khi tạo báo giá.',
            ]);
        }

        $available = $this->availability->forProducts($cartItems->pluck('product'));
        $lines = [];
        $couponLines = [];
        $cartSubtotal = 0;

        foreach ($cartItems as $cartItem) {
            $product = $cartItem->product;
            $label = $product->name ?: "Sản phẩm #{$product->id}";

            if ($cartItem->user_id !== $user->id || $cartItem->quantity < 1) {
                $this->invalidCartLine($label, 'Số lượng trong giỏ không hợp lệ.');
            }

            if (! $product->isPubliclyEligible()) {
                $this->invalidCartLine($label, 'Sản phẩm, danh mục hoặc thương hiệu không còn được bán.');
            }

            $availableQuantity = $available[$product->id] ?? 0;
            if ($cartItem->quantity > $availableQuantity) {
                $this->invalidCartLine($label, "Chỉ còn {$availableQuantity} sản phẩm khả dụng.");
            }

            try {
                $unitPrice = $product->effectivePriceVnd();
                $lineSubtotal = $cartItem->subtotalVnd($unitPrice);
            } catch (Throwable $exception) {
                if (! $exception instanceof OverflowException && ! $exception instanceof \UnexpectedValueException) {
                    throw $exception;
                }

                $this->invalidCartLine($label, 'Giá trị sản phẩm vượt giới hạn tính toán an toàn.');
            }

            if ($lineSubtotal > PHP_INT_MAX - $cartSubtotal) {
                throw ValidationException::withMessages([
                    'cart' => 'Tổng giá trị giỏ hàng vượt giới hạn tính toán an toàn.',
                ]);
            }
            $cartSubtotal += $lineSubtotal;

            $lines[] = new CheckoutQuoteLine(
                $product->id,
                $product->category_id,
                $product->brand_id,
                $product->sku,
                $product->name,
                $cartItem->quantity,
                $unitPrice,
                $lineSubtotal,
            );
            $couponLines[] = [
                'product_id' => $product->id,
                'category_id' => $product->category_id,
                'brand_id' => $product->brand_id,
                'unit_price_vnd' => $unitPrice,
                'quantity' => $cartItem->quantity,
            ];
        }

        try {
            $shipping = $this->shippingRates->handle(
                $this->shippingRegions->handle($recipient->province),
            );
        } catch (ShippingRateUnavailable $exception) {
            throw ValidationException::withMessages(['province' => $exception->getMessage()]);
        }

        $shippingSnapshot = new CheckoutShippingSnapshot(
            $shipping['snapshot']['shipping_rate_id'],
            $shipping['snapshot']['region_key'],
            $shipping['snapshot']['region_label'],
            $shipping['snapshot']['shipping_fee_vnd'],
        );

        $couponSnapshot = null;
        $productDiscount = 0;
        $shippingDiscount = 0;

        if ($couponCode !== null) {
            $coupon = Coupon::query()->where('code', $couponCode)->first();

            if ($coupon === null) {
                throw ValidationException::withMessages([
                    'coupon_code' => 'Mã giảm giá không tồn tại.',
                ]);
            }

            $tier = MembershipLevel::tryFrom((string) $user->getRawOriginal('current_tier'));
            if ($tier === null) {
                throw new AuthorizationException('Invalid customer membership tier.');
            }

            $evaluation = $this->coupons->handle(
                $coupon,
                $couponLines,
                $shippingSnapshot->shippingFeeVnd,
                $tier,
                $quotedAt,
            );

            if (! $evaluation->eligible) {
                throw ValidationException::withMessages([
                    'coupon_code' => $this->couponFailureMessage($evaluation->reason),
                ]);
            }

            $productDiscount = $evaluation->itemDiscountVnd;
            $shippingDiscount = $evaluation->shippingDiscountVnd;
            $couponSnapshot = new CheckoutCouponSnapshot(
                $coupon->id,
                $coupon->code,
                $coupon->type->value,
                $coupon->scope->value,
                $coupon->value,
                $evaluation->eligibleSubtotalVnd,
            );
        }

        $shippingAfterDiscount = $shippingSnapshot->shippingFeeVnd - $shippingDiscount;
        $itemsAfterDiscount = $cartSubtotal - $productDiscount;

        if ($shippingAfterDiscount < 0 || $itemsAfterDiscount < 0
            || $shippingAfterDiscount > PHP_INT_MAX - $itemsAfterDiscount
            || $shippingDiscount > PHP_INT_MAX - $productDiscount) {
            throw ValidationException::withMessages([
                'cart' => 'Bảng tính vượt giới hạn tiền VND được hỗ trợ.',
            ]);
        }

        return new CheckoutQuote(
            $recipient,
            $shippingSnapshot,
            $lines,
            $couponSnapshot,
            $cartSubtotal,
            $productDiscount,
            $shippingSnapshot->shippingFeeVnd,
            $shippingDiscount,
            $shippingAfterDiscount,
            $productDiscount + $shippingDiscount,
            $itemsAfterDiscount + $shippingAfterDiscount,
            $quotedAt,
        );
    }

    private function assertActiveCustomer(User $user): void
    {
        if (UserRole::tryFrom((string) $user->getRawOriginal('role')) !== UserRole::Customer
            || UserStatus::tryFrom((string) $user->getRawOriginal('status')) !== UserStatus::Active) {
            throw new AuthorizationException('Only active customers can create checkout quotes.');
        }
    }

    private function invalidCartLine(string $product, string $reason): never
    {
        throw ValidationException::withMessages([
            'cart' => "{$product}: {$reason}",
        ]);
    }

    private function couponFailureMessage(?string $reason): string
    {
        return match ($reason) {
            'inactive' => 'Mã giảm giá đang không hoạt động.',
            'not_started' => 'Mã giảm giá chưa bắt đầu.',
            'expired' => 'Mã giảm giá đã hết hạn.',
            'tier_not_eligible' => 'Hạng thành viên hiện tại chưa đủ điều kiện dùng mã.',
            'no_eligible_items' => 'Giỏ hàng không có sản phẩm thuộc phạm vi của mã.',
            'minimum_not_met' => 'Giá trị sản phẩm đủ điều kiện chưa đạt mức tối thiểu của mã.',
            default => 'Mã giảm giá không hợp lệ cho báo giá này.',
        };
    }
}
