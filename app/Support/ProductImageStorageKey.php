<?php

namespace App\Support;

final class ProductImageStorageKey
{
    public static function cloudinaryPrefix(int $productId): string
    {
        return rtrim((string) config('product-images.cloudinary.folder'), '/')."/products/{$productId}/";
    }

    public static function ownsCloudinaryId(int $productId, string $publicId): bool
    {
        $prefix = self::cloudinaryPrefix($productId);
        $suffix = str_starts_with($publicId, $prefix) ? substr($publicId, strlen($prefix)) : '';

        return $suffix !== ''
            && ! str_contains($suffix, '/')
            && ! str_contains($suffix, '\\')
            && ! str_contains($suffix, "\0")
            && ! str_contains($suffix, '..');
    }

    public static function ownsLocalPath(int $productId, string $path): bool
    {
        $prefix = "products/{$productId}/";
        $filename = str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : '';

        return $filename !== ''
            && ! str_contains($filename, '/')
            && ! str_contains($filename, '\\')
            && ! str_contains($filename, "\0")
            && ! in_array($filename, ['.', '..'], true);
    }
}
