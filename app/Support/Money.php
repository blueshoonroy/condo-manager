<?php

namespace App\Support;

use InvalidArgumentException;

class Money
{
    public static function cents(string $value): int
    {
        $value = trim($value);
        if (! preg_match('/^-?\d{1,8}(?:\.\d{1,2})?$/D', $value)) {
            throw new InvalidArgumentException('Enter an amount with at most two decimal places.');
        }
        $negative = str_starts_with($value, '-');
        $parts = explode('.', ltrim($value, '-'));
        $cents = ((int) $parts[0] * 100) + (int) str_pad($parts[1] ?? '', 2, '0');

        return $negative ? -$cents : $cents;
    }

    public static function format(int $cents): string
    {
        return ($cents < 0 ? '-$' : '$').number_format(abs($cents) / 100, 2);
    }
}
