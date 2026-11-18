<?php

namespace Database\Factories;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Generation>
 */
class GenerationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'id' => Str::uuid()->toString(),
            'user_id' => User::factory(),
            'provider_id' => 'gen_'.Str::upper(Str::random(24)),
            'idempotency_key' => Str::uuid()->toString(),
            'status' => Generation::STATUS_QUEUED,
            'prompt' => fake()->sentence(),
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
            'estimated_provider_cost_nano' => 8_800_000,
            'estimated_charge_nano' => 11_000_000,
            'reserved_nano' => 11_000_000,
        ];
    }

    public function succeeded(): static
    {
        return $this->state(fn (): array => [
            'status' => Generation::STATUS_SUCCEEDED,
            'actual_provider_cost_nano' => 7_200_000,
            'actual_charge_nano' => 9_000_000,
            'provider_usage' => [
                'input_text_tokens' => 12,
                'input_image_tokens' => 0,
                'output_tokens' => 1100,
                'reasoning_tokens' => 200,
                'total_tokens' => 1312,
            ],
            'image_url' => 'https://pixelforge.promptedit.dev/images/example.png',
            'provider_completed_at' => now(),
            'settled' => true,
        ]);
    }
}
