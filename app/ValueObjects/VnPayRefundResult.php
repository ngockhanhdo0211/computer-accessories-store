<?php

namespace App\ValueObjects;

use App\Enums\RefundGatewayAttemptStatus;
use Carbon\CarbonImmutable;

final readonly class VnPayRefundResult
{
    public function __construct(
        public RefundGatewayAttemptStatus $status,
        public ?string $responseCode,
        public ?string $transactionStatus,
        public ?string $gatewayReference,
        public ?string $responseFingerprint,
        public CarbonImmutable $completedAt,
    ) {}

    public static function ambiguous(?string $responseFingerprint = null): self
    {
        return new self(
            RefundGatewayAttemptStatus::Ambiguous,
            null,
            null,
            null,
            $responseFingerprint,
            CarbonImmutable::now('UTC'),
        );
    }
}
