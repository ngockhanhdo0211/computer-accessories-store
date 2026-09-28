<?php

namespace App\ValueObjects;

use InvalidArgumentException;

final readonly class CheckoutRecipient
{
    public function __construct(
        public string $name,
        public string $email,
        public string $phone,
        public string $province,
        public string $district,
        public string $ward,
        public string $addressLine,
    ) {
        foreach ([
            'name' => [$name, 255],
            'province' => [$province, 100],
            'district' => [$district, 100],
            'ward' => [$ward, 100],
            'address line' => [$addressLine, 500],
        ] as $field => [$value, $maximum]) {
            if (trim($value) === '' || mb_strlen($value) > $maximum) {
                throw new InvalidArgumentException("Checkout recipient {$field} is invalid.");
            }
        }

        if (mb_strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Checkout recipient email is invalid.');
        }

        if (preg_match('/^0[35789][0-9]{8}$/', $phone) !== 1) {
            throw new InvalidArgumentException('Checkout recipient phone is invalid.');
        }
    }

    public function fullAddress(): string
    {
        return implode(', ', [$this->addressLine, $this->ward, $this->district, $this->province]);
    }

    /** @return array<string, string> */
    public function snapshot(): array
    {
        return [
            'recipient_name' => $this->name,
            'recipient_email' => $this->email,
            'recipient_phone' => $this->phone,
            'recipient_address' => $this->fullAddress(),
            'recipient_region' => $this->province,
        ];
    }
}
