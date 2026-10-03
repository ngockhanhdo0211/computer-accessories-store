<?php

namespace App\ValueObjects;

use Carbon\CarbonImmutable;

final readonly class VnPayCallback
{
    /** @param array<string, string> $fingerprintFields */
    public function __construct(
        public string $terminalCode,
        public int $amountVnd,
        public string $orderInfo,
        public string $reference,
        public string $responseCode,
        public string $transactionStatus,
        public ?string $transactionId,
        public CarbonImmutable $paidAt,
        public ?string $bankCode,
        public array $fingerprintFields,
    ) {}

    public function succeeded(): bool
    {
        return $this->responseCode === '00' && $this->transactionStatus === '00';
    }

    public function fingerprint(): string
    {
        $fields = $this->fingerprintFields;
        ksort($fields, SORT_STRING);

        return hash('sha256', http_build_query($fields, '', '&', PHP_QUERY_RFC1738));
    }
}
