<?php

namespace Tests\Unit;

use App\Services\Pricing\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_from_dollars_parses_exact_decimal_strings(): void
    {
        $this->assertSame(373_875_000, Money::fromDollars('0.373875000'));
        $this->assertSame(1_500_000_000, Money::fromDollars('1.5'));
        $this->assertSame(-1_500_000_000, Money::fromDollars('-1.5'));
    }

    public function test_to_dollars_pads_fraction_to_nine_places(): void
    {
        $this->assertSame('0.000000005', Money::toDollars(5));
        $this->assertSame('-1.500000000', Money::toDollars(-1_500_000_000));
    }

    public function test_with_markup_adds_a_quarter_without_floats(): void
    {
        $this->assertSame(11_000_000, Money::withMarkup(8_800_000));
        $this->assertSame(0, Money::withMarkup(0));
    }

    public function test_rate_per_token_converts_dollars_per_million_to_nanodollars(): void
    {
        $this->assertSame(5_000, Money::ratePerTokenNano('5.00'));
        $this->assertSame(12_500, Money::ratePerTokenNano('12.50'));
    }
}
