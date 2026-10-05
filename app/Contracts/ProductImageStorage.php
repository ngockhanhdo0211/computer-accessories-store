<?php

namespace App\Contracts;

use App\ValueObjects\StoredProductImage;
use Illuminate\Http\UploadedFile;

interface ProductImageStorage
{
    public function store(int $productId, UploadedFile $file, string $format): StoredProductImage;

    public function delete(int $productId, StoredProductImage $image): void;
}
