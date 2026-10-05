<?php

namespace App\Contracts;

interface ProductImageStorageResolver
{
    public function current(): ProductImageStorage;

    public function forProvider(string $provider): ProductImageStorage;
}
