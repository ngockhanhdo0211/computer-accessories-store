<?php

namespace App\Exceptions;

use RuntimeException;

class VnPayCallbackConflict extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $paymentAttemptId = null,
        public readonly ?string $oldFingerprint = null,
        public readonly ?string $newFingerprint = null,
        public readonly string $reasonCode = 'callback_conflict',
    ) {
        parent::__construct($message);
    }
}
