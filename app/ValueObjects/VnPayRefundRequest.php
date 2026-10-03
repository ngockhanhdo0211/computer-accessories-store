<?php

namespace App\ValueObjects;

final readonly class VnPayRefundRequest
{
    /** @param array<string, string> $parameters */
    public function __construct(
        public array $parameters,
        public string $requestFingerprint,
    ) {}

    public function requestId(): string
    {
        return $this->parameters['vnp_RequestId'];
    }
}
