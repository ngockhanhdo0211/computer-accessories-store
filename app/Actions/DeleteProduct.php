<?php

namespace App\Actions;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class DeleteProduct
{
    public function handle(Product $product): void
    {
        try {
            DB::transaction(function () use ($product) {
                $locked = Product::query()->lockForUpdate()->findOrFail($product->id);
                $productId = (int) $locked->getKey();
                $images = ProductImage::query()->where('product_id', $productId)->lockForUpdate()->get();
                $paths = $images->pluck('path')->all();

                ProductImage::query()->where('product_id', $productId)->delete();
                $locked->delete();

                DB::afterCommit(fn () => $this->deleteOwnedFiles($productId, $paths));
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

    /** @param array<int, string> $paths */
    private function deleteOwnedFiles(int $productId, array $paths): void
    {
        try {
            $disk = Storage::disk('public');
        } catch (Throwable $exception) {
            Log::warning('Product file cleanup disk is unavailable after deletion.', [
                'productId' => $productId,
                'exception' => $exception::class,
            ]);

            return;
        }

        foreach ($paths as $path) {
            if (! $this->isOwnedPath($productId, $path)) {
                Log::warning('Skipped unsafe Product cleanup path.', compact('productId', 'path'));

                continue;
            }

            try {
                if ($disk->exists($path) && ! $disk->delete($path)) {
                    Log::warning('Product file cleanup failed after product deletion.', compact('productId', 'path'));
                }
            } catch (Throwable $exception) {
                Log::warning('Product file cleanup threw after product deletion.', [
                    ...compact('productId', 'path'),
                    'exception' => $exception::class,
                ]);
            }
        }
    }
}
