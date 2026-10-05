<?php

namespace App\Services;

use App\Contracts\ProductImageStorage;
use App\Contracts\ProductImageStorageResolver;
use Illuminate\Contracts\Container\Container;
use RuntimeException;

class ProductImageStorageManager implements ProductImageStorageResolver
{
    public function __construct(private readonly Container $container) {}

    public function current(): ProductImageStorage
    {
        return $this->forProvider((string) config('product-images.driver'));
    }

    public function forProvider(string $provider): ProductImageStorage
    {
        return match ($provider) {
            'local' => $this->container->make(LocalProductImageStorage::class),
            'cloudinary' => $this->container->make(CloudinaryProductImageStorage::class),
            default => throw new RuntimeException('Unsupported Product image storage provider.'),
        };
    }
}
