<?php

namespace App\Services\Pricing;

final class Money
{
    public const NANO_PER_DOLLAR = 1_000_000_000;

    public const MARKUP_PERCENT = 25;

    public static function fromDollars(string $amount): int
    {
        $negative = str_starts_with($amount, '-');
        $unsigned = ltrim($amount, '+-');
        [$whole, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
        $fraction = substr($fraction.str_repeat('0', 9), 0, 9);
        $nano = ((int) $whole) * self::NANO_PER_DOLLAR + (int) $fraction;

        return $negative ? -$nano : $nano;
    }

    public static function toDollars(int $nano, int $precision = 9): string
    {
        $negative = $nano < 0;
        $unsigned = abs($nano);
        $whole = intdiv($unsigned, self::NANO_PER_DOLLAR);
        $fraction = str_pad((string) ($unsigned % self::NANO_PER_DOLLAR), 9, '0', STR_PAD_LEFT);
        $value = $whole.'.'.substr($fraction, 0, min($precision, 9));

        return $negative ? '-'.$value : $value;
    }

    public static function format(int $nano): string
    {
        return '$'.self::toDollars($nano, 6);
    }

    public static function withMarkup(int $providerCostNano): int
    {
        return intdiv($providerCostNano * (100 + self::MARKUP_PERCENT), 100);
    }

    public static function ratePerTokenNano(string $dollarsPerMillion): int
    {
        return intdiv(self::fromDollars($dollarsPerMillion), 1_000_000);
    }
}
