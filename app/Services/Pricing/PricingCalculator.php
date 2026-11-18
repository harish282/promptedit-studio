<?php

namespace App\Services\Pricing;

final class PricingCalculator
{
    private const TOKEN_TYPES = [
        'input_text_tokens',
        'input_image_tokens',
        'output_tokens',
        'reasoning_tokens',
    ];

    /**
     * @param  array<string, mixed>  $pricing
     * @param  array<int, string>  $referenceImages
     * @return array{provider_cost_nano: int, charge_nano: int, tokens: array<string, int>, rates: array<string, string>}
     */
    public function estimate(array $pricing, string $prompt, string $size, string $quality, array $referenceImages): array
    {
        /** @var array<string, string> $rates */
        $rates = data_get($pricing, 'rates', []);

        $baseTextTokens = (int) ceil(
            mb_strlen($prompt) / max(1, (int) data_get($pricing, 'tokens.characters_per_text_token', 4))
        );
        $variance = (float) data_get($pricing, 'tokens.text_token_variance', 0.15);

        $tokens = [
            'input_text_tokens' => (int) ceil($baseTextTokens * (1 + $variance)),
            'input_image_tokens' => (int) data_get($pricing, 'tokens.per_reference_image', 765) * count($referenceImages),
            'output_tokens' => (int) data_get($pricing, "tokens.output.{$quality}.{$size}", 0),
            'reasoning_tokens' => (int) data_get($pricing, "tokens.reasoning.{$quality}.max", 0),
        ];

        $providerCostNano = $this->cost($rates, $tokens);

        return [
            'provider_cost_nano' => $providerCostNano,
            'charge_nano' => Money::withMarkup($providerCostNano),
            'tokens' => $tokens,
            'rates' => $rates,
        ];
    }

    /**
     * @param  array<string, string>  $rates
     * @param  array<string, mixed>  $usage
     */
    public function actual(array $rates, array $usage): int
    {
        $tokens = [];

        foreach (self::TOKEN_TYPES as $type) {
            $tokens[$type] = (int) ($usage[$type] ?? 0);
        }

        return $this->cost($rates, $tokens);
    }

    /**
     * @param  array<string, string>  $rates
     * @param  array<string, int>  $tokens
     */
    private function cost(array $rates, array $tokens): int
    {
        $total = 0;

        foreach (self::TOKEN_TYPES as $type) {
            $rate = $rates[$type] ?? null;

            if ($rate === null) {
                continue;
            }

            $total += $tokens[$type] * Money::ratePerTokenNano((string) $rate);
        }

        return $total;
    }
}
