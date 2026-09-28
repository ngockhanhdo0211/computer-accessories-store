<?php

namespace App\Support;

final class PhoneNumberNormalizer
{
    public static function normalize(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $phone = preg_replace('/[\s().-]+/u', '', trim($value));

        if ($phone === null) {
            return $value;
        }

        if (str_starts_with($phone, '+84')) {
            return '0'.substr($phone, 3);
        }

        if (str_starts_with($phone, '84') && strlen($phone) === 11) {
            return '0'.substr($phone, 2);
        }

        return $phone;
    }
}
