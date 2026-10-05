<?php

namespace App\Actions;

use App\Contracts\ProductImageStorageResolver;
use App\Models\Product;
use App\Models\ProductImage;
use App\Support\ProductImageStorageKey;
use App\ValueObjects\StoredProductImage;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class DeleteProduct
{
    public function __construct(private readonly ProductImageStorageResolver $storage) {}

    public function handle(Product $product): void
    {
        try {
            DB::transaction(function () use ($product) {
                $locked = Product::query()->lockForUpdate()->findOrFail($product->id);
                $productId = (int) $locked->getKey();
                $images = ProductImage::query()->where('product_id', $productId)->lockForUpdate()->get();
                $storedImages = $images->map->storedImage()->all();

                foreach ($storedImages as $storedImage) {
                    if ($storedImage->provider === 'cloudinary'
                        && (! is_string($storedImage->cloudinaryPublicId)
                            || ! ProductImageStorageKey::ownsCloudinaryId($productId, $storedImage->cloudinaryPublicId))) {
                        throw new \RuntimeException('Unsafe Cloudinary Product image identity.');
                    }
                }

                ProductImage::query()->where('product_id', $productId)->delete();
                $locked->delete();

                DB::afterCommit(fn () => $this->deleteStoredImages($productId, $storedImages));
            }, 3);
        } catch (QueryException $exception) {
            if ($this->isProductForeignKeyViolation($exception)) {
                throw ValidationException::withMessages([
                    'product' => 'Không thể xóa sản phẩm đã phát sinh dữ liệu nghiệp vụ. Hãy ẩn sản phẩm thay thế.',
                ]);
            }

            throw $exception;
        }
    }

    private function isProductForeignKeyViolation(QueryException $exception): bool
    {
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);
        $details = strtolower($exception->getMessage());

        return match (DB::getDriverName()) {
            'mysql' => $driverCode === 1451
                && str_contains($details, 'foreign key constraint fails')
                && preg_match('/references\s+[`"]?products[`"]?\s*\(/', $details) === 1,
            'sqlite' => $driverCode === 19 && str_contains($exception->getMessage(), 'FOREIGN KEY constraint failed'),
            default => false,
        };
    }

    /** @param array<int, StoredProductImage> $images */
    private function deleteStoredImages(int $productId, array $images): void
    {
        $cloudinaryCleanupFailed = false;

        foreach ($images as $image) {
            try {
                $this->storage->forProvider($image->provider)->delete($productId, $image);
            } catch (Throwable $exception) {
                Log::error('Product image cleanup failed after product deletion.', [
                    'product_id' => $productId,
                    'provider' => $image->provider,
                    'cloudinary_public_id' => $image->provider === 'cloudinary' ? $image->cloudinaryPublicId : null,
                    'exception' => $exception::class,
                ]);
                if ($image->provider === 'cloudinary') {
                    $cloudinaryCleanupFailed = true;
                }
            }
        }

        if ($cloudinaryCleanupFailed) {
            throw new \RuntimeException('One or more Cloud image cleanups require reconciliation.');
        }
    }
}
