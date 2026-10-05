<?php

namespace App\Actions;

use App\Contracts\ProductImageStorageResolver;
use App\Models\Product;
use App\Models\ProductImage;
use App\Support\ProductImageStorageKey;
use App\ValueObjects\StoredProductImage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class DeleteProductImage
{
    public function __construct(private readonly ProductImageStorageResolver $storage) {}

    public function handle(Product $product, ProductImage $image): void
    {
        DB::transaction(function () use ($product, $image) {
            $locked = Product::query()->lockForUpdate()->findOrFail($product->id);
            $target = ProductImage::query()
                ->where('product_id', $locked->id)
                ->whereKey($image->id)
                ->lockForUpdate()
                ->firstOrFail();
            $imageId = (int) $target->getKey();
            $storedImage = $target->storedImage();
            if ($storedImage->provider === 'cloudinary'
                && (! is_string($storedImage->cloudinaryPublicId)
                    || ! ProductImageStorageKey::ownsCloudinaryId((int) $locked->getKey(), $storedImage->cloudinaryPublicId))) {
                throw new RuntimeException('Unsafe Cloudinary Product image identity.');
            }
            $wasPrimary = $target->is_primary;
            $target->delete();

            $remaining = ProductImage::query()->where('product_id', $locked->id)
                ->lockForUpdate()->orderBy('sort_order')->orderBy('id')->get();

            foreach ($remaining as $order => $remainingImage) {
                $remainingImage->update([
                    'sort_order' => $order,
                    'is_primary' => $wasPrimary ? $order === 0 : $remainingImage->is_primary,
                ]);
            }

            DB::afterCommit(fn () => $this->cleanup((int) $locked->getKey(), $imageId, $storedImage));
        }, 3);
    }

    private function cleanup(int $productId, int $imageId, StoredProductImage $image): void
    {
        try {
            $this->storage->forProvider($image->provider)->delete($productId, $image);
        } catch (Throwable $exception) {
            Log::error('Product image cleanup failed after metadata deletion.', [
                'product_id' => $productId,
                'image_id' => $imageId,
                'provider' => $image->provider,
                'cloudinary_public_id' => $image->provider === 'cloudinary' ? $image->cloudinaryPublicId : null,
                'exception' => $exception::class,
            ]);
            if ($image->provider === 'cloudinary') {
                throw new RuntimeException('Cloud image cleanup requires reconciliation.', previous: $exception);
            }
        }
    }
}
