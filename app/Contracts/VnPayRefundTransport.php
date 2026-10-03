<?php

namespace App\Contracts;

use App\ValueObjects\VnPayRefundRequest;
use App\ValueObjects\VnPayRefundResult;

interface VnPayRefundTransport
{
    public function send(VnPayRefundRequest $request): VnPayRefundResult;
}
