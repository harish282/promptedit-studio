<?php

namespace App\Http\Controllers;

use App\Models\WebhookEvent;
use App\Services\Generations\GenerationService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class WebhookController extends Controller
{
    public function __invoke(Request $request, GenerationService $service): Response
    {
        $payload = $request->getContent();
        $signature = [];

        foreach (explode(',', (string) $request->header('Pixelforge-Signature')) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            $signature[trim($key)] = trim($value);
        }

        $timestamp = (int) ($signature['t'] ?? 0);
        $expected = hash_hmac(
            'sha256',
            "{$timestamp}.{$payload}",
            (string) config('services.pixelforge.webhook_secret'),
        );

        if (! hash_equals($expected, $signature['v1'] ?? '') || abs(time() - $timestamp) > 300) {
            abort(400, 'Invalid Pixelforge signature.');
        }

        /** @var array<string, mixed>|null $event */
        $event = json_decode($payload, true);

        if (! is_array($event) || ! is_string($event['id'] ?? null) || ! is_string($event['type'] ?? null)) {
            abort(422, 'Invalid Pixelforge event.');
        }

        $object = data_get($event, 'data.object');

        try {
            DB::transaction(function () use ($event, $object, $request, $service, $timestamp): void {
                WebhookEvent::create([
                    'id' => $event['id'],
                    'type' => $event['type'],
                    'generation_id' => is_array($object) ? data_get($object, 'metadata.local_id') : null,
                    'provider_generation_id' => is_array($object) ? ($object['id'] ?? null) : null,
                    'delivery_attempt' => (int) $request->header('Pixelforge-Delivery-Attempt', '0'),
                    'signature_timestamp' => $timestamp,
                    'payload' => $event,
                    'received_at' => now(),
                ]);

                $service->applyWebhookEvent($event);
            });
        } catch (UniqueConstraintViolationException) {
            return response()->noContent();
        }

        return response()->noContent();
    }
}
