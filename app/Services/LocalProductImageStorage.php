<?php

namespace App\Services;

use App\Contracts\ProductImageStorage;
use App\Support\ProductImageStorageKey;
use App\ValueObjects\StoredProductImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class LocalProductImageStorage implements ProductImageStorage
{
    public function store(int $productId, UploadedFile $file, string $format): StoredProductImage
    {
        $path = $file->storeAs("products/{$productId}", Str::uuid().'.'.$format, 'public');
        if (! is_string($path)) {
            throw ValidationException::withMessages([
                'images' => 'Không thể lưu ảnh. Vui lòng thử lại.',
            ]);
        }

        return new StoredProductImage('local', $path, null, null, null, null, null, null);
    }

    public function delete(int $productId, StoredProductImage $image): void
    {
        if ($image->provider !== 'local' || ! is_string($image->path) || ! ProductImageStorageKey::ownsLocalPath($productId, $image->path)) {
            throw new RuntimeException('Unsafe local Product image identity.');
        }

        $disk = Storage::disk('public');
        if ($disk->exists($image->path) && ! $disk->delete($image->path)) {
            throw new RuntimeException('Local Product image deletion failed.');
        }
    }
}
