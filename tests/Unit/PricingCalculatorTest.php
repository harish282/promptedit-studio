<?php

namespace Tests\Unit;

use App\Services\Pricing\PricingCalculator;
use PHPUnit\Framework\TestCase;

class PricingCalculatorTest extends TestCase
{
    public function test_estimate_uses_unicode_characters_and_maximum_token_counts(): void
    {
        $result = (new PricingCalculator)->estimate(
            $this->pricing(),
            str_repeat('é', 400),
            '1536x1024',
            'high',
            ['https://example.com/one.png', 'https://example.com/two.png'],
        );

        $this->assertSame([
            'input_text_tokens' => 115,
            'input_image_tokens' => 1530,
            'output_tokens' => 6450,
            'reasoning_tokens' => 8000,
        ], $result['tokens']);
        $this->assertSame(373_875_000, $result['provider_cost_nano']);
        $this->assertSame(467_343_750, $result['charge_nano']);
    }

    public function test_actual_cost_uses_provider_usage_rates(): void
    {
        $cost = (new PricingCalculator)->actual($this->pricing()['rates'], [
            'input_text_tokens' => 12,
            'input_image_tokens' => 765,
            'output_tokens' => 6450,
            'reasoning_tokens' => 3184,
        ]);

        $this->assertSame(305_510_000, $cost);
    }

    /**
     * @return array<string, mixed>
     */
    private function pricing(): array
    {
        return [
            'rates' => [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.50',
            ],
            'tokens' => [
                'characters_per_text_token' => 4,
                'text_token_variance' => 0.15,
                'per_reference_image' => 765,
                'output' => [
                    'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
                    'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
                    'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
                ],
                'reasoning' => [
                    'low' => ['min' => 0, 'max' => 500],
                    'medium' => ['min' => 200, 'max' => 2000],
                    'high' => ['min' => 1000, 'max' => 8000],
                ],
            ],
        ];
    }
}
