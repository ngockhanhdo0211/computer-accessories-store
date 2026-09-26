<?php

namespace App\Actions;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class DeleteProductImage
{
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
            $path = $target->path;
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

            DB::afterCommit(fn () => $this->deleteOwnedFile((int) $locked->getKey(), $imageId, $path));
        }, 3);
    }

    private function isOwnedPath(int $productId, string $path): bool
    {
        $prefix = "products/{$productId}/";
        $filename = str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : '';

        return $filename !== ''
            && ! str_contains($filename, '/')
            && ! str_contains($filename, '\\')
            && ! str_contains($filename, "\0")
            && ! in_array($filename, ['.', '..'], true);
    }

    private function deleteOwnedFile(int $productId, int $imageId, string $path): void
    {
        if (! $this->isOwnedPath($productId, $path)) {
            Log::warning('Skipped unsafe Product image cleanup path.', compact('productId', 'imageId', 'path'));

            return;
        }

        try {
            $disk = Storage::disk('public');

            if ($disk->exists($path) && ! $disk->delete($path)) {
                Log::warning('Product image file cleanup failed after metadata deletion.', compact('productId', 'imageId', 'path'));
            }
        } catch (Throwable $exception) {
            Log::warning('Product image file cleanup threw after metadata deletion.', [
                ...compact('productId', 'imageId', 'path'),
                'exception' => $exception::class,
            ]);
        }
    }
}
