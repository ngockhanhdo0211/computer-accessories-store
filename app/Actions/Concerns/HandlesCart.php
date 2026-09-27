<?php

namespace App\Actions\Concerns;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use OverflowException;
use UnexpectedValueException;

trait HandlesCart
{
    private function assertCustomer(User $user): void
    {
        if (
            UserRole::tryFrom((string) $user->getRawOriginal('role')) !== UserRole::Customer
            || UserStatus::tryFrom((string) $user->getRawOriginal('status')) !== UserStatus::Active
        ) {
            throw new AuthorizationException;
        }
    }

    /**
     * Lock and return a currently public product.
     */
    private function lockEligibleProduct(int $productId): Product
    {
        $product = Product::query()->lockForUpdate()->findOrFail($productId);
        $category = Category::query()->find($product->category_id);
        $brand = Brand::query()->find($product->brand_id);

        $product->setRelation('category', $category);
        $product->setRelation('brand', $brand);

        if (! $product->isPubliclyEligible()) {
            throw ValidationException::withMessages([
                'product' => 'Sản phẩm này hiện không thể mua công khai.',
            ]);
        }

        try {
            $product->effectivePriceVnd();
        } catch (UnexpectedValueException) {
            throw ValidationException::withMessages([
                'product' => 'Giá hiện tại của sản phẩm không hợp lệ.',
            ]);
        }

        return $product;
    }

    private function addQuantities(int $current, int $added): int
    {
        if ($current < 0 || $added < 1 || $current > PHP_INT_MAX - $added) {
            throw new OverflowException('Cart quantity exceeds the supported integer range.');
        }

        return $current + $added;
    }

    private function assertSupportedSubtotal(Product $product, int $quantity): void
    {
        $unitPrice = $product->effectivePriceVnd();

        if ($unitPrice !== 0 && $quantity > intdiv(PHP_INT_MAX, $unitPrice)) {
            throw ValidationException::withMessages([
                'quantity' => 'Số lượng làm giá trị dòng giỏ vượt quá giới hạn hỗ trợ.',
            ]);
        }
    }
}
