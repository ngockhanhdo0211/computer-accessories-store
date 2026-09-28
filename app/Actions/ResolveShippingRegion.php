<?php

namespace App\Actions;

use App\Enums\ShippingRegion;
use Illuminate\Support\Str;

class ResolveShippingRegion
{
    public function handle(string $province): ShippingRegion
    {
        $normalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $province)));
        $ascii = strtolower(trim(preg_replace('/\s+/', ' ', Str::ascii($normalized))));

        return $ascii === 'ha noi'
            ? ShippingRegion::HaNoi
            : ShippingRegion::Other;
    }
}
