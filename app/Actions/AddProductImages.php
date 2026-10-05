<?php

namespace App\Actions;

use App\Contracts\ProductImageStorageResolver;
use App\Models\Product;
use App\Models\ProductImage;
use App\ValueObjects\StoredProductImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class AddProductImages
{
    public const MAX_IMAGES = 8;

    public function __construct(private readonly ProductImageStorageResolver $storage) {}

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

        $prepared = [];

        foreach ($files as $index => $file) {
            $imageInfo = @getimagesize($file->getRealPath());
            $format = match ($imageInfo['mime'] ?? null) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                default => null,
            };
            if ($format === null) {
                throw ValidationException::withMessages([
                    "images.{$index}" => 'Ảnh có định dạng không hợp lệ.',
                ]);
            }
            $prepared[] = [$file, $format];
        }

        if (ProductImage::query()->where('product_id', $product->id)->count() + count($prepared) > self::MAX_IMAGES) {
            throw ValidationException::withMessages(['images' => 'Mỗi sản phẩm có tối đa 8 ảnh.']);
        }

        /** @var array<int, StoredProductImage> $storedImages */
        $storedImages = [];
        $adapter = $this->storage->current();

        try {
            foreach ($prepared as [$file, $format]) {
                $storedImages[] = $adapter->store((int) $product->id, $file, $format);
            }

            return DB::transaction(function () use ($product, $storedImages, $altTexts) {
                $locked = Product::query()->lockForUpdate()->findOrFail($product->id);
                $existing = ProductImage::query()->where('product_id', $locked->id)->lockForUpdate()->get();

                if ($existing->count() + count($storedImages) > self::MAX_IMAGES) {
                    throw ValidationException::withMessages(['images' => 'Mỗi sản phẩm có tối đa 8 ảnh.']);
                }

                $nextOrder = ((int) $existing->max('sort_order')) + ($existing->isEmpty() ? 0 : 1);
                $hasPrimary = $existing->contains('is_primary', true);
                $created = [];

                foreach ($storedImages as $index => $storedImage) {
                    $image = new ProductImage;
                    $image->forceFill([
                        ...$storedImage->attributes(),
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
            foreach (array_reverse($storedImages) as $storedImage) {
                try {
                    $this->storage->forProvider($storedImage->provider)
                        ->delete((int) $product->id, $storedImage);
                } catch (Throwable $cleanupException) {
                    Log::warning('Product upload rollback cleanup failed.', [
                        'product_id' => (int) $product->id,
                        'provider' => $storedImage->provider,
                        'exception' => $cleanupException::class,
                    ]);
                }
            }

            throw $exception;
        }
    }
}
