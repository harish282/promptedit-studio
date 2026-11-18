<?php

namespace App\Services\Pixelforge;

use App\Exceptions\PixelforgeRequestFailed;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

final class PixelforgeClient
{
    private const MAX_ATTEMPTS = 4;

    private const MAX_RETRY_AFTER_SECONDS = 30;

    public function pricing(): array
    {
        return Cache::remember('pixelforge.pricing', 3600, function (): array {
            try {
                /** @var array<string, mixed> $pricing */
                $pricing = $this->request(false)->get('/pricing')->throw()->json();

                return $pricing;
            } catch (ConnectionException) {
                throw new PixelforgeRequestFailed('Pricing is temporarily unavailable. Please try again.', 0, 'connection_error');
            } catch (RequestException $exception) {
                throw new PixelforgeRequestFailed(
                    'Pricing is temporarily unavailable. Please try again.',
                    $exception->response->status(),
                    'pricing_unavailable',
                );
            }
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createGeneration(array $payload, string $idempotencyKey): array
    {
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                $response = $this->request()
                    ->withHeaders(['Idempotency-Key' => $idempotencyKey])
                    ->post('/generations', $payload);

                if ($response->successful()) {
                    /** @var array<string, mixed> $data */
                    $data = $response->json();

                    return $data;
                }

                $code = (string) $response->json('error.code');

                if ($this->isRetryable($code) && $attempt < self::MAX_ATTEMPTS) {
                    $this->wait($response->header('Retry-After'), $attempt);

                    continue;
                }

                throw new PixelforgeRequestFailed(
                    $this->friendlyMessage($code, (string) $response->json('error.message')),
                    $response->status(),
                    $code,
                );
            } catch (ConnectionException $exception) {
                if ($attempt >= self::MAX_ATTEMPTS) {
                    throw new PixelforgeRequestFailed('Pixelforge could not be reached. Please try again.', 0, 'connection_error');
                }

                $this->wait(null, $attempt);
            }
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listGenerations(): array
    {
        try {
            /** @var array<string, mixed> $data */
            $data = $this->request()
                ->get('/generations', ['limit' => 100])
                ->throw()
                ->json();
        } catch (ConnectionException) {
            throw new PixelforgeRequestFailed('Pixelforge could not be reached. Please try again.', 0, 'connection_error');
        } catch (RequestException $exception) {
            throw new PixelforgeRequestFailed(
                'Pixelforge could not list generations. Please try again.',
                $exception->response->status(),
                (string) $exception->response->json('error.code'),
            );
        }

        $generations = $data['data'] ?? null;

        return is_array($generations) ? array_values(array_filter($generations, 'is_array')) : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function generation(string $providerId): ?array
    {
        try {
            $response = $this->request()->get('/generations/'.$providerId);
        } catch (ConnectionException) {
            throw new PixelforgeRequestFailed('Pixelforge could not be reached. Please try again.', 0, 'connection_error');
        }

        if ($response->notFound()) {
            return null;
        }

        try {
            $response->throw();
        } catch (RequestException $exception) {
            throw new PixelforgeRequestFailed(
                'Pixelforge could not refresh this image. Please try again.',
                $exception->response->status(),
                (string) $exception->response->json('error.code'),
            );
        }

        /** @var array<string, mixed>|null $data */
        $data = $response->json();

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function usage(string $startDate, string $endDate): array
    {
        try {
            /** @var array<string, mixed> $data */
            $data = $this->request()
                ->get('/usage', ['start_date' => $startDate, 'end_date' => $endDate])
                ->throw()
                ->json();

            return $data;
        } catch (ConnectionException) {
            throw new PixelforgeRequestFailed('Pixelforge usage is temporarily unavailable.', 0, 'connection_error');
        } catch (RequestException $exception) {
            throw new PixelforgeRequestFailed(
                'Pixelforge usage is temporarily unavailable.',
                $exception->response->status(),
                (string) $exception->response->json('error.code'),
            );
        }
    }

    private function request(bool $authenticated = true): PendingRequest
    {
        $request = Http::baseUrl((string) config('services.pixelforge.url'))
            ->connectTimeout(5)
            ->timeout(15)
            ->acceptJson()
            ->asJson();

        return $authenticated
            ? $request->withToken((string) config('services.pixelforge.key'))
            : $request;
    }

    private function isRetryable(string $code): bool
    {
        return in_array($code, [
            'rate_limit_exceeded',
            'concurrency_limit_exceeded',
            'overloaded',
        ], true);
    }

    private function wait(?string $retryAfter, int $attempt): void
    {
        $seconds = (int) $retryAfter;

        if ($seconds <= 0) {
            $seconds = min(2 ** $attempt, 8);
        }

        Sleep::sleep(min($seconds, self::MAX_RETRY_AFTER_SECONDS));
    }

    private function friendlyMessage(string $code, string $providerMessage): string
    {
        return match ($code) {
            'content_policy_violation' => 'Pixelforge rejected that prompt under its content policy.',
            'rate_limit_exceeded' => 'Pixelforge is busy right now. Please try again in a moment.',
            'concurrency_limit_exceeded' => 'Pixelforge has too many images in progress. Please try again shortly.',
            'daily_limit_exceeded' => 'Pixelforge has reached its daily limit. Please try again tomorrow.',
            'overloaded' => 'Pixelforge is temporarily overloaded. Please try again.',
            'validation_failed' => 'That request was rejected by Pixelforge. Check your prompt and settings.',
            default => $providerMessage !== '' ? $providerMessage : 'Pixelforge rejected the request.',
        };
    }
}
