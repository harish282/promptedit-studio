<?php

namespace Tests\Feature;

use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReconcileGenerationsTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.pixelforge.url', 'https://pixelforge.promptedit.dev/v1');
        config()->set('services.pixelforge.key', 'pf_test');
    }

    public function test_reconciles_a_succeeded_generation_without_a_webhook(): void
    {
        $user = User::factory()->create(['balance_nano' => 9_500_000_000]);
        $generation = Generation::factory()->create([
            'user_id' => $user->id,
            'provider_id' => null,
            'status' => Generation::STATUS_QUEUED,
            'estimated_provider_cost_nano' => 400_000_000,
            'estimated_charge_nano' => 500_000_000,
            'reserved_nano' => 500_000_000,
            'created_at' => now()->subMinute(),
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://pixelforge.promptedit.dev/v1/generations*' => Http::response([
                'data' => [$this->providerGeneration('succeeded', $generation->id)],
            ]),
        ]);

        $this->artisan('app:reconcile-generations')->assertSuccessful();

        $generation->refresh();

        $this->assertSame(Generation::STATUS_SUCCEEDED, $generation->status);
        $this->assertSame('gen_reconciled', $generation->provider_id);
        $this->assertSame(305_510_000, $generation->actual_provider_cost_nano);
        $this->assertSame(381_887_500, $generation->actual_charge_nano);
        $this->assertTrue($generation->settled);
        $this->assertSame(9_618_112_500, $user->refresh()->balance_nano);

        $this->artisan('app:reconcile-generations')->assertSuccessful();

        $this->assertSame(9_618_112_500, $user->refresh()->balance_nano);
        $this->assertDatabaseCount('credit_transactions', 1);
    }

    public function test_reconciles_a_failed_generation_for_free(): void
    {
        $user = User::factory()->create(['balance_nano' => 9_500_000_000]);
        $generation = Generation::factory()->create([
            'user_id' => $user->id,
            'provider_id' => null,
            'status' => Generation::STATUS_PROCESSING,
            'reserved_nano' => 500_000_000,
            'created_at' => now()->subMinute(),
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://pixelforge.promptedit.dev/v1/generations*' => Http::response([
                'data' => [$this->providerGeneration('failed', $generation->id)],
            ]),
        ]);

        $this->artisan('app:reconcile-generations')->assertSuccessful();

        $generation->refresh();

        $this->assertSame(Generation::STATUS_FAILED, $generation->status);
        $this->assertSame(0, $generation->actual_provider_cost_nano);
        $this->assertSame(0, $generation->actual_charge_nano);
        $this->assertTrue($generation->settled);
        $this->assertSame(10_000_000_000, $user->refresh()->balance_nano);
        $this->assertDatabaseHas('credit_transactions', [
            'generation_id' => $generation->id,
            'type' => CreditTransaction::TYPE_REFUND,
            'amount_nano' => 500_000_000,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function providerGeneration(string $status, string $localId): array
    {
        return [
            'id' => 'gen_reconciled',
            'object' => 'generation',
            'status' => $status,
            'prompt' => 'A lighthouse',
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
            'metadata' => ['local_id' => $localId],
            'image_url' => $status === 'succeeded' ? 'https://example.com/image.png' : null,
            'image_url_expires_at' => null,
            'usage' => $status === 'succeeded' ? [
                'input_text_tokens' => 12,
                'input_image_tokens' => 765,
                'output_tokens' => 6450,
                'reasoning_tokens' => 3184,
                'total_tokens' => 10411,
            ] : null,
            'rates' => [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.50',
            ],
            'error' => $status === 'failed' ? ['code' => 'generation_failed', 'message' => 'No image.'] : null,
            'created_at' => now()->subMinutes(2)->toIso8601String(),
            'started_at' => now()->subMinutes(2)->toIso8601String(),
            'completed_at' => now()->subMinute()->toIso8601String(),
        ];
    }
}
