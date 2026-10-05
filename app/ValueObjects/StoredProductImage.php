<?php

namespace App\ValueObjects;

final readonly class StoredProductImage
{
    public function __construct(
        public string $provider,
        public ?string $path,
        public ?string $cloudinaryPublicId,
        public ?string $secureUrl,
        public ?int $width,
        public ?int $height,
        public ?int $bytes,
        public ?string $format,
    ) {}

    /** @return array<string, int|string|null> */
    public function attributes(): array
    {
        return [
            'storage_provider' => $this->provider,
            'path' => $this->path,
            'cloudinary_public_id' => $this->cloudinaryPublicId,
            'secure_url' => $this->secureUrl,
            'width' => $this->width,
            'height' => $this->height,
            'bytes' => $this->bytes,
            'format' => $this->format,
        ];
    }
}
