<?php

namespace App\Exceptions;

use RuntimeException;

class VnPayIpnException extends RuntimeException
{
    public function __construct(public readonly string $responseCode, string $message)
    {
        parent::__construct($message);
    }
}
