<?php

namespace App\ValueObjects;

final readonly class VnPayIpnResponse
{
    public function __construct(public string $code, public string $message) {}

    /** @return array{RspCode:string, Message:string} */
    public function toArray(): array
    {
        return ['RspCode' => $this->code, 'Message' => $this->message];
    }
}
