<?php

namespace App\Actions;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReorderProductImages
{
    /** @param array<int, int> $orderedIds */
    public function handle(Product $product, array $orderedIds, int $primaryId): void
    {
        DB::transaction(function () use ($product, $orderedIds, $primaryId) {
            $locked = Product::query()->lockForUpdate()->findOrFail($product->id);
            $images = ProductImage::query()->where('product_id', $locked->id)->lockForUpdate()->get()->keyBy('id');
            $actual = $images->keys()->map(fn ($id) => (int) $id)->sort()->values()->all();
            $submitted = collect($orderedIds)->map(fn ($id) => (int) $id)->sort()->values()->all();

            if ($actual !== $submitted || ! in_array($primaryId, $submitted, true)) {
                throw ValidationException::withMessages([
                    'ordered_image_ids' => 'Danh sách ảnh không khớp với sản phẩm.',
                ]);
            }

            foreach ($orderedIds as $order => $id) {
                $images[(int) $id]->update([
                    'sort_order' => $order,
                    'is_primary' => (int) $id === $primaryId,
                ]);
            }
        }, 3);
    }
}
