<?php

namespace App\Actions;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class AddProductImages
{
    public const MAX_IMAGES = 8;

    /**
     * @param  array<int, UploadedFile>  $files
     * @param  array<int, string|null>  $altTexts
     * @return array<int, ProductImage>
     */
    public function handle(Product $product, array $files, array $altTexts = []): array
    {
        if ($files === []) {
            return [];
        }

        $validated = Validator::make([
            'images' => $files,
            'image_alt_texts' => $altTexts,
        ], [
            'images' => ['required', 'array', 'min:1', 'max:'.self::MAX_IMAGES],
            'images.*' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'image_alt_texts' => ['array'],
            'image_alt_texts.*' => ['nullable', 'string', 'max:255'],
        ])->validate();

        $files = $validated['images'];
        $altTexts = array_map(
            fn ($value) => is_string($value) ? trim($value) : $value,
            $validated['image_alt_texts'] ?? []
        );

        $storedPaths = [];

        try {
            foreach ($files as $index => $file) {
                $imageInfo = @getimagesize($file->getRealPath());
                $extension = match ($imageInfo['mime'] ?? null) {
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/webp' => 'webp',
                    default => null,
                };

                if ($extension === null) {
                    throw ValidationException::withMessages([
                        "images.{$index}" => 'Ảnh có định dạng không hợp lệ.',
                    ]);
                }
                $path = $file->storeAs(
                    'products/'.$product->id,
                    Str::uuid().'.'.$extension,
                    'public'
                );

                if (! is_string($path)) {
                    throw ValidationException::withMessages(['images' => 'Không thể lưu ảnh. Vui lòng thử lại.']);
                }

                $storedPaths[] = $path;
            }

            return DB::transaction(function () use ($product, $storedPaths, $altTexts) {
                $locked = Product::query()->lockForUpdate()->findOrFail($product->id);
                $existing = ProductImage::query()->where('product_id', $locked->id)->lockForUpdate()->get();

                if ($existing->count() + count($storedPaths) > self::MAX_IMAGES) {
                    throw ValidationException::withMessages(['images' => 'Mỗi sản phẩm có tối đa 8 ảnh.']);
                }

                $nextOrder = ((int) $existing->max('sort_order')) + ($existing->isEmpty() ? 0 : 1);
                $hasPrimary = $existing->contains('is_primary', true);
                $created = [];

                foreach ($storedPaths as $index => $path) {
                    $image = new ProductImage;
                    $image->forceFill([
                        'path' => $path,
                        'alt_text' => $altTexts[$index] ?? null,
                        'is_primary' => ! $hasPrimary && $index === 0,
                        'sort_order' => $nextOrder + $index,
                    ]);
                    $image->product()->associate($locked);
                    $image->save();
                    $created[] = $image;
                }

                return $created;
            }, 3);
        } catch (Throwable $exception) {
            if ($storedPaths !== []) {
                try {
                    Storage::disk('public')->delete($storedPaths);
                } catch (Throwable $cleanupException) {
                    Log::warning('Product upload rollback file cleanup failed.', [
                        'product_id' => $product->id,
                        'paths' => $storedPaths,
                        'exception' => $cleanupException::class,
                    ]);
                }
            }

            throw $exception;
        }
    }
}
