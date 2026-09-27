<?php

namespace App\Actions;

use App\Actions\Concerns\HandlesCart;
use App\Models\User;
use OverflowException;

class GetCartSummary
{
    use HandlesCart;

    public function __construct(private readonly CalculateAvailableStock $availability) {}

    /**
     * @return array{items: Illuminate\Support\Collection<int, array<string, mixed>>, total_vnd: ?int, total_overflow: bool, line_count: int}
     */
    public function handle(User $user): array
    {
        $this->assertCustomer($user);

        $cartItems = $user->cartItems()
            ->with([
                'product.category:id,name,is_visible',
                'product.brand:id,name,is_visible',
                'product.primaryImage:id,product_id,path,alt_text',
            ])
            ->orderBy('id')
            ->get();

        $available = $this->availability->forProducts($cartItems->pluck('product'));
        $total = 0;
        $totalOverflow = false;

        $items = $cartItems->map(function ($cartItem) use ($available, &$total, &$totalOverflow): array {
            $product = $cartItem->product;
            $isPublic = $product->isPubliclyEligible();
            $availableQuantity = $available[$product->id] ?? 0;
            $unitPrice = $product->effectivePriceVnd();
            $subtotal = null;
            $hasMoneyOverflow = false;

            try {
                $subtotal = $cartItem->subtotalVnd($unitPrice);
            } catch (OverflowException) {
                $hasMoneyOverflow = true;
            }

            $isPurchasable = $isPublic
                && ! $hasMoneyOverflow
                && $cartItem->quantity <= $availableQuantity;

            if ($isPurchasable && ! $totalOverflow) {
                if ($subtotal > PHP_INT_MAX - $total) {
                    $total = null;
                    $totalOverflow = true;
                } else {
                    $total += $subtotal;
                }
            }

            return compact(
                'cartItem',
                'product',
                'unitPrice',
                'subtotal',
                'availableQuantity',
                'isPublic',
                'isPurchasable',
                'hasMoneyOverflow',
            );
        });

        return [
            'items' => $items,
            'total_vnd' => $total,
            'total_overflow' => $totalOverflow,
            'line_count' => $cartItems->count(),
        ];
    }
}
