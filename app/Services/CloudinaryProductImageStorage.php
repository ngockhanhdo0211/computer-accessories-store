<?php

namespace App\Services;

use App\Contracts\ProductImageStorage;
use App\Support\ProductImageStorageKey;
use App\ValueObjects\StoredProductImage;
use Cloudinary\Cloudinary;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class CloudinaryProductImageStorage implements ProductImageStorage
{
    private Cloudinary $cloudinary;

    public function __construct()
    {
        $config = config('product-images.cloudinary');
        foreach (['cloud_name', 'api_key', 'api_secret', 'folder'] as $key) {
            if (! is_string($config[$key] ?? null) || trim($config[$key]) === '') {
                throw new RuntimeException('Cloudinary Product image configuration is incomplete.');
            }
        }

        $this->cloudinary = new Cloudinary([
            'cloud' => [
                'cloud_name' => $config['cloud_name'],
                'api_key' => $config['api_key'],
                'api_secret' => $config['api_secret'],
            ],
            'url' => ['secure' => true],
        ]);
    }

    public function store(int $productId, UploadedFile $file, string $format): StoredProductImage
    {
        $folder = rtrim(ProductImageStorageKey::cloudinaryPrefix($productId), '/');
        $publicName = (string) Str::uuid();
        $expectedPublicId = $folder.'/'.$publicName;
        try {
            $response = $this->cloudinary->uploadApi()->upload($file->getRealPath(), [
                'resource_type' => 'image',
                'folder' => $folder,
                'public_id' => $publicName,
                'overwrite' => false,
                'unique_filename' => false,
                'use_filename' => false,
            ]);
        } catch (Throwable) {
            $this->bestEffortDestroy($expectedPublicId);

            throw new RuntimeException('Cloudinary Product image upload was not confirmed.');
        }

        $publicId = $response['public_id'] ?? null;
        $secureUrl = $response['secure_url'] ?? null;
        $resourceType = $response['resource_type'] ?? null;
        $actualFormat = strtolower((string) ($response['format'] ?? ''));
        $width = filter_var($response['width'] ?? null, FILTER_VALIDATE_INT);
        $height = filter_var($response['height'] ?? null, FILTER_VALIDATE_INT);
        $bytes = filter_var($response['bytes'] ?? null, FILTER_VALIDATE_INT);

        if ($publicId !== $expectedPublicId || ! ProductImageStorageKey::ownsCloudinaryId($productId, $expectedPublicId)
            || ! is_string($secureUrl) || ! str_starts_with($secureUrl, 'https://')
            || $resourceType !== 'image'
            || ! in_array($actualFormat, ['jpg', 'png', 'webp'], true)
            || $actualFormat !== $format
            || $width === false || $width <= 0 || $height === false || $height <= 0 || $bytes === false || $bytes <= 0) {
            $this->bestEffortDestroy($expectedPublicId);
            throw new RuntimeException('Cloudinary returned invalid Product image evidence.');
        }

        return new StoredProductImage('cloudinary', null, $publicId, $secureUrl, $width, $height, $bytes, $actualFormat);
    }

    public function delete(int $productId, StoredProductImage $image): void
    {
        if ($image->provider !== 'cloudinary' || ! is_string($image->cloudinaryPublicId)
            || ! ProductImageStorageKey::ownsCloudinaryId($productId, $image->cloudinaryPublicId)) {
            throw new RuntimeException('Unsafe Cloudinary Product image identity.');
        }

        try {
            $response = $this->cloudinary->uploadApi()->destroy($image->cloudinaryPublicId, [
                'resource_type' => 'image',
                'invalidate' => true,
            ]);
        } catch (Throwable) {
            throw new RuntimeException('Cloudinary Product image deletion was not confirmed.');
        }
        if (! in_array($response['result'] ?? null, ['ok', 'not found'], true)) {
            throw new RuntimeException('Cloudinary Product image deletion was not confirmed.');
        }
    }

    private function bestEffortDestroy(string $publicId): void
    {
        try {
            $this->cloudinary->uploadApi()->destroy($publicId, [
                'resource_type' => 'image',
                'invalidate' => true,
            ]);
        } catch (Throwable $exception) {
            Log::error('Cloudinary Product image rollback cleanup requires reconciliation.', [
                'cloudinary_public_id' => $publicId,
                'exception' => $exception::class,
            ]);
        }
    }
}
