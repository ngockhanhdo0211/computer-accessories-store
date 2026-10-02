<?php

namespace App\ValueObjects;

use App\Models\PaymentAttempt;

final readonly class VnPayInitiation
{
    public function __construct(
        public PaymentAttempt $attempt,
        public string $paymentUrl,
    ) {}
}
