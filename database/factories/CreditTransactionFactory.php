<?php

namespace Database\Factories;

use App\Models\CreditTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreditTransaction>
 */
class CreditTransactionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => CreditTransaction::TYPE_PURCHASE,
            'amount_nano' => 5_000_000_000,
            'description' => 'Credit purchase',
        ];
    }
}
