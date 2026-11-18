<?php

namespace Tests\Feature;

use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class GenerationFlowTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.pixelforge.url', 'https://pixelforge.promptedit.dev/v1');
        config()->set('services.pixelforge.key', 'pf_test');
    }

    public function test_estimate_endpoint_returns_cost_from_live_pricing(): void
    {
        $this->seedPricing();
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/generations/estimate', [
            'prompt' => str_repeat('a', 400),
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => ['https://example.com/one.png', 'https://example.com/two.png'],
        ])->assertOk()->assertJson([
            'provider_cost_nano' => 373_875_000,
            'charge_nano' => 467_343_750,
            'tokens' => [
                'input_text_tokens' => 115,
                'input_image_tokens' => 1530,
                'output_tokens' => 6450,
                'reasoning_tokens' => 8000,
            ],
        ]);
    }

    public function test_estimate_endpoint_rejects_an_invalid_size(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/generations/estimate', [
            'prompt' => 'A lighthouse',
            'size' => '800x600',
            'quality' => 'medium',
        ])->assertUnprocessable()->assertJsonValidationErrors('size');
    }

    public function test_store_creates_generation_and_reserves_the_estimated_charge(): void
    {
        $this->seedPricing();
        Http::preventStrayRequests();
        Http::fake([
            'https://pixelforge.promptedit.dev/v1/generations' => Http::response($this->providerGeneration(), 202),
        ]);

        $user = User::factory()->create();

        $this->actingAs($user)->post('/generations', [
            'prompt' => 'A lighthouse',
            'size' => '1024x1024',
            'quality' => 'medium',
        ])->assertRedirect('/generations');

        $generation = Generation::firstOrFail();

        $this->assertSame(Generation::STATUS_QUEUED, $generation->status);
        $this->assertSame($user->id, $generation->user_id);
        $this->assertSame(69_020_000, $generation->estimated_provider_cost_nano);
        $this->assertSame(86_275_000, $generation->reserved_nano);
        $this->assertSame($generation->id, $generation->idempotency_key);
        $this->assertSame(9_913_725_000, $user->refresh()->balance_nano);
        $this->assertDatabaseHas('credit_transactions', [
            'generation_id' => $generation->id,
            'type' => CreditTransaction::TYPE_RESERVATION,
            'amount_nano' => -86_275_000,
        ]);

        Http::assertSent(function (ClientRequest $request) use ($generation): bool {
            return $request->url() === 'https://pixelforge.promptedit.dev/v1/generations'
                && $request['metadata']['local_id'] === $generation->id
                && $request->hasHeader('Idempotency-Key', $generation->idempotency_key);
        });
    }

    public function test_store_refunds_reservation_when_provider_rejects_submission(): void
    {
        $this->seedPricing();
        Http::preventStrayRequests();
        Http::fake([
            'https://pixelforge.promptedit.dev/v1/generations' => Http::response([
                'error' => [
                    'type' => 'invalid_request_error',
                    'code' => 'content_policy_violation',
                    'message' => 'Prompt blocked.',
                ],
            ], 400),
        ]);

        $user = User::factory()->create();

        $this->from('/generate')->actingAs($user)->post('/generations', [
            'prompt' => 'A lighthouse',
            'size' => '1024x1024',
            'quality' => 'medium',
        ])->assertRedirect('/generate')->assertSessionHas('error');

        $generation = Generation::firstOrFail();

        $this->assertSame(Generation::STATUS_FAILED, $generation->status);
        $this->assertSame(0, $generation->actual_charge_nano);
        $this->assertTrue($generation->settled);
        $this->assertSame(10_000_000_000, $user->refresh()->balance_nano);
        $this->assertDatabaseHas('credit_transactions', [
            'generation_id' => $generation->id,
            'type' => CreditTransaction::TYPE_RESERVATION,
            'amount_nano' => -86_275_000,
        ]);
        $this->assertDatabaseHas('credit_transactions', [
            'generation_id' => $generation->id,
            'type' => CreditTransaction::TYPE_REFUND,
            'amount_nano' => 86_275_000,
        ]);
    }

    public function test_store_rejects_generation_when_balance_is_insufficient(): void
    {
        $this->seedPricing();
        Http::preventStrayRequests();
        $user = User::factory()->create(['balance_nano' => 1]);

        $this->from('/generate')->actingAs($user)->post('/generations', [
            'prompt' => 'A lighthouse',
            'size' => '1024x1024',
            'quality' => 'medium',
        ])->assertRedirect('/generate')->assertSessionHasErrors('balance');

        $this->assertDatabaseCount('generations', 0);
        $this->assertSame(1, $user->refresh()->balance_nano);
    }

    public function test_history_does_not_show_another_users_generations(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        Generation::factory()->for($otherUser)->succeeded()->create();

        $this->actingAs($user)->get('/generations')->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Generations/Index')
                ->where('generations.data', []));
    }

    public function test_store_retries_an_overloaded_request_after_retry_after(): void
    {
        $this->seedPricing();
        Sleep::fake();
        Http::preventStrayRequests();
        Http::fake([
            'https://pixelforge.promptedit.dev/v1/generations' => Http::sequence()
                ->push(['error' => ['code' => 'overloaded', 'message' => 'Overloaded.']], 503, ['Retry-After' => '2'])
                ->push($this->providerGeneration(), 202),
        ]);

        $user = User::factory()->create();

        $this->actingAs($user)->post('/generations', [
            'prompt' => 'A lighthouse',
            'size' => '1024x1024',
            'quality' => 'medium',
        ])->assertRedirect('/generations');

        Sleep::assertSequence([Sleep::for(2)->seconds()]);
        $this->assertSame(Generation::STATUS_QUEUED, Generation::firstOrFail()->status);
    }

    public function test_store_does_not_retry_a_daily_limit_error(): void
    {
        $this->seedPricing();
        Sleep::fake();
        Http::preventStrayRequests();
        Http::fake([
            'https://pixelforge.promptedit.dev/v1/generations' => Http::response([
                'error' => ['code' => 'daily_limit_exceeded', 'message' => 'Daily limit reached.'],
            ], 429, ['Retry-After' => '60']),
        ]);

        $user = User::factory()->create(['balance_nano' => 10_000_000_000]);

        $this->from('/generate')->actingAs($user)->post('/generations', [
            'prompt' => 'A lighthouse',
            'size' => '1024x1024',
            'quality' => 'medium',
        ])->assertRedirect('/generate');

        Sleep::assertNeverSlept();
        $generation = Generation::firstOrFail();

        $this->assertSame(Generation::STATUS_FAILED, $generation->status);
        $this->assertSame(0, $generation->actual_charge_nano);
        $this->assertTrue($generation->settled);
        $this->assertSame(10_000_000_000, $user->refresh()->balance_nano);
    }

    public function test_store_leaves_a_connection_failure_queued_for_reconciliation(): void
    {
        $this->seedPricing();
        Sleep::fake();
        Http::preventStrayRequests();
        Http::fake([
            'https://pixelforge.promptedit.dev/v1/generations' => Http::failedConnection(),
        ]);

        $user = User::factory()->create(['balance_nano' => 10_000_000_000]);

        $this->from('/generate')->actingAs($user)->post('/generations', [
            'prompt' => 'A lighthouse',
            'size' => '1024x1024',
            'quality' => 'medium',
        ])->assertRedirect('/generate');

        $generation = Generation::firstOrFail();

        $this->assertSame(Generation::STATUS_QUEUED, $generation->status);
        $this->assertSame('submission_uncertain', $generation->error_code);
        $this->assertNull($generation->actual_charge_nano);
        $this->assertFalse($generation->settled);
        $this->assertSame(9_913_725_000, $user->refresh()->balance_nano);
    }

    public function test_refresh_returns_current_state_when_pixelforge_is_unavailable(): void
    {
        $user = User::factory()->create();
        $generation = Generation::factory()->for($user)->create([
            'provider_id' => 'gen_test123',
            'status' => Generation::STATUS_QUEUED,
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://pixelforge.promptedit.dev/v1/generations/gen_test123' => Http::response([
                'error' => ['code' => 'overloaded', 'message' => 'Overloaded.'],
            ], 503),
        ]);

        $this->actingAs($user)->postJson("/generations/{$generation->id}/refresh")
            ->assertOk()
            ->assertJsonPath('status', Generation::STATUS_QUEUED);
    }

    /**
     * @return array<string, mixed>
     */
    private function seedPricing(): void
    {
        Cache::flush();
        Cache::put('pixelforge.pricing', [
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
        ], 3600);
    }

    /**
     * @return array<string, mixed>
     */
    private function providerGeneration(): array
    {
        return [
            'id' => 'gen_test123',
            'object' => 'generation',
            'status' => 'queued',
            'prompt' => 'A lighthouse',
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
            'metadata' => [],
            'image_url' => null,
            'image_url_expires_at' => null,
            'usage' => null,
            'rates' => [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.50',
            ],
            'error' => null,
            'created_at' => '2026-11-17T05:00:00Z',
            'started_at' => null,
            'completed_at' => null,
        ];
    }
}
