<?php

namespace App\Support;

final class Quantity
{
    public const MAX = 999999999999;

    /** Convert validated decimal text to thousandths without floating point arithmetic. */
    public static function parse(string $value): int
    {
        $negative = str_starts_with($value, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($value, '-'), 2), 2, '');
        $scaled = (int) $whole * 1000 + (int) str_pad($fraction, 3, '0');

        return $negative ? -$scaled : $scaled;
    }

    public static function format(int $value): string
    {
        return ($value < 0 ? '-' : '').intdiv(abs($value), 1000).'.'.str_pad((string) (abs($value) % 1000), 3, '0', STR_PAD_LEFT);
    }
}
