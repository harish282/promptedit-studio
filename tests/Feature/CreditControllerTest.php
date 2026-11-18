<?php

namespace Tests\Feature;

use App\Models\CreditTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class CreditControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_purchase_adds_five_dollars_and_records_transaction(): void
    {
        $user = User::factory()->create(['balance_nano' => 1_000_000_000]);

        $this->actingAs($user)->post('/credits/purchase')->assertRedirect();

        $this->assertSame(6_000_000_000, $user->refresh()->balance_nano);
        $this->assertDatabaseHas('credit_transactions', [
            'user_id' => $user->id,
            'type' => CreditTransaction::TYPE_PURCHASE,
            'amount_nano' => 5_000_000_000,
        ]);
    }
}
