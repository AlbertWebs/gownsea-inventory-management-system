<?php

namespace App\Support;

use InvalidArgumentException;

class KenyanPhoneNumber
{
    public static function normalize(string $phone): string
    {
        $normalized = preg_replace('/[\s().-]+/', '', trim($phone));
        if (! is_string($normalized) || $normalized === '') {
            throw new InvalidArgumentException('Enter a valid Kenyan phone number.');
        }

        if (str_starts_with($normalized, '+254')) {
            $normalized = substr($normalized, 4);
        } elseif (str_starts_with($normalized, '254')) {
            $normalized = substr($normalized, 3);
        } elseif (str_starts_with($normalized, '0')) {
            $normalized = substr($normalized, 1);
        }

        if (preg_match('/^[1-9]\d{8}$/', $normalized) !== 1) {
            throw new InvalidArgumentException('Enter a valid Kenyan phone number.');
        }

        return '+254'.$normalized;
    }
}
