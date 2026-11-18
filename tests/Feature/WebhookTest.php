<?php

namespace Tests\Feature;

use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.pixelforge.webhook_secret', 'whsec_test');
    }

    public function test_rejects_request_with_invalid_signature(): void
    {
        [$payload, $server] = $this->signedEvent($this->succeededEvent('gen_ignored', 'not-a-local-id'));
        $server['HTTP_PIXELFORGE_SIGNATURE'] = 't='.time().',v1='.str_repeat('0', 64);

        $this->call('POST', '/webhooks/pixelforge', [], [], [], $server, $payload)
            ->assertBadRequest();

        $this->assertDatabaseCount('webhook_events', 0);
    }

    public function test_accepts_succeeded_event_once_and_settles_balance(): void
    {
        $user = User::factory()->create(['balance_nano' => 9_989_000_000]);
        $generation = Generation::factory()->create([
            'user_id' => $user->id,
            'provider_id' => null,
            'status' => Generation::STATUS_QUEUED,
            'reserved_nano' => 11_000_000,
            'estimated_charge_nano' => 11_000_000,
            'estimated_provider_cost_nano' => 8_800_000,
            'settled' => false,
        ]);
        $event = $this->succeededEvent('gen_webhook123', $generation->id);

        [$payload, $server] = $this->signedEvent($event);
        $this->call('POST', '/webhooks/pixelforge', [], [], [], $server, $payload)->assertNoContent();

        $generation->refresh();
        $this->assertSame(Generation::STATUS_SUCCEEDED, $generation->status);
        $this->assertSame('gen_webhook123', $generation->provider_id);
        $this->assertSame(305_510_000, $generation->actual_provider_cost_nano);
        $this->assertSame(381_887_500, $generation->actual_charge_nano);
        $this->assertTrue($generation->settled);
        $this->assertSame(9_618_112_500, $user->refresh()->balance_nano);
        $this->assertDatabaseHas('credit_transactions', [
            'generation_id' => $generation->id,
            'type' => CreditTransaction::TYPE_RESERVATION,
            'amount_nano' => -370_887_500,
        ]);

        [, $server] = $this->signedEvent($event);
        $this->call('POST', '/webhooks/pixelforge', [], [], [], $server, $payload)->assertNoContent();

        $this->assertDatabaseCount('webhook_events', 1);
        $this->assertSame(9_618_112_500, $user->refresh()->balance_nano);
    }

    public function test_stale_processing_event_does_not_reopen_succeeded_generation(): void
    {
        $generation = Generation::factory()->succeeded()->create();
        $event = [
            'id' => 'evt_processing',
            'object' => 'event',
            'type' => 'generation.processing',
            'created_at' => '2026-11-17T06:00:00Z',
            'data' => ['object' => [
                'id' => $generation->provider_id,
                'object' => 'generation',
                'status' => Generation::STATUS_PROCESSING,
                'metadata' => ['local_id' => $generation->id],
            ]],
        ];

        [$payload, $server] = $this->signedEvent($event);
        $this->call('POST', '/webhooks/pixelforge', [], [], [], $server, $payload)->assertNoContent();

        $this->assertSame(Generation::STATUS_SUCCEEDED, $generation->refresh()->status);
        $this->assertDatabaseCount('webhook_events', 1);
    }

    public function test_rejects_a_stale_signature(): void
    {
        [$payload] = $this->signedEvent($this->succeededEvent('gen_ignored', 'not-a-local-id'));
        $timestamp = time() - 400;
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", 'whsec_test');

        $this->call('POST', '/webhooks/pixelforge', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PIXELFORGE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        ], $payload)->assertBadRequest();

        $this->assertDatabaseCount('webhook_events', 0);
    }

    public function test_failed_event_refunds_the_entire_reservation(): void
    {
        $user = User::factory()->create(['balance_nano' => 9_913_725_000]);
        $generation = Generation::factory()->create([
            'user_id' => $user->id,
            'status' => Generation::STATUS_PROCESSING,
            'reserved_nano' => 86_275_000,
            'estimated_charge_nano' => 86_275_000,
        ]);

        $event = [
            'id' => 'evt_failed',
            'object' => 'event',
            'type' => 'generation.failed',
            'created_at' => '2026-11-17T06:00:00Z',
            'data' => ['object' => [
                'id' => 'gen_failed',
                'object' => 'generation',
                'status' => Generation::STATUS_FAILED,
                'metadata' => ['local_id' => $generation->id],
                'error' => ['code' => 'generation_failed', 'message' => 'No image was produced.'],
                'created_at' => '2026-11-17T05:00:00Z',
                'completed_at' => '2026-11-17T06:00:00Z',
            ]],
        ];

        [$payload, $server] = $this->signedEvent($event);
        $this->call('POST', '/webhooks/pixelforge', [], [], [], $server, $payload)->assertNoContent();

        $generation->refresh();

        $this->assertSame(Generation::STATUS_FAILED, $generation->status);
        $this->assertSame(0, $generation->actual_provider_cost_nano);
        $this->assertSame(0, $generation->actual_charge_nano);
        $this->assertTrue($generation->settled);
        $this->assertSame(10_000_000_000, $user->refresh()->balance_nano);
        $this->assertDatabaseHas('credit_transactions', [
            'generation_id' => $generation->id,
            'type' => CreditTransaction::TYPE_REFUND,
            'amount_nano' => 86_275_000,
        ]);
    }

    public function test_unknown_generation_event_is_recorded_without_changes(): void
    {
        [$payload, $server] = $this->signedEvent($this->succeededEvent('gen_unknown', 'missing-local-id'));

        $this->call('POST', '/webhooks/pixelforge', [], [], [], $server, $payload)->assertNoContent();

        $this->assertDatabaseCount('webhook_events', 1);
        $this->assertDatabaseHas('webhook_events', [
            'id' => 'evt_succeeded',
            'type' => 'generation.succeeded',
        ]);
        $this->assertDatabaseCount('generations', 0);
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array{0: string, 1: array<string, string>}
     */
    private function signedEvent(array $event): array
    {
        $payload = json_encode($event, JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", 'whsec_test');

        return [
            $payload,
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_PIXELFORGE_SIGNATURE' => "t={$timestamp},v1={$signature}",
                'HTTP_PIXELFORGE_EVENT_ID' => $event['id'],
                'HTTP_PIXELFORGE_DELIVERY_ATTEMPT' => '1',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function succeededEvent(string $providerId, string $localId): array
    {
        return [
            'id' => 'evt_succeeded',
            'object' => 'event',
            'type' => 'generation.succeeded',
            'created_at' => '2026-11-17T06:00:00Z',
            'data' => ['object' => [
                'id' => $providerId,
                'object' => 'generation',
                'status' => Generation::STATUS_SUCCEEDED,
                'prompt' => 'A lighthouse',
                'size' => '1024x1024',
                'quality' => 'medium',
                'reference_images' => [],
                'metadata' => ['local_id' => $localId],
                'image_url' => 'https://pixelforge.promptedit.dev/images/test.png',
                'image_url_expires_at' => '2026-11-18T06:00:00Z',
                'usage' => [
                    'input_text_tokens' => 12,
                    'input_image_tokens' => 765,
                    'output_tokens' => 6450,
                    'reasoning_tokens' => 3184,
                    'total_tokens' => 10411,
                ],
                'rates' => [
                    'input_text_tokens' => '5.00',
                    'input_image_tokens' => '10.00',
                    'output_tokens' => '40.00',
                    'reasoning_tokens' => '12.50',
                ],
                'error' => null,
                'created_at' => '2026-11-17T05:00:00Z',
                'started_at' => '2026-11-17T05:00:05Z',
                'completed_at' => '2026-11-17T06:00:00Z',
            ]],
        ];
    }
}
