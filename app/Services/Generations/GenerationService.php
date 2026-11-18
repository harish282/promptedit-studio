<?php

namespace App\Services\Generations;

use App\Exceptions\PixelforgeRequestFailed;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pricing\Money;
use App\Services\Pricing\PricingCalculator;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class GenerationService
{
    public function __construct(
        private readonly PixelforgeClient $client,
        private readonly PricingCalculator $calculator,
    ) {}

    /**
     * @param  array{prompt: string, size: string, quality: string, reference_images?: array<int, string>}  $data
     * @return array{provider_cost_nano: int, charge_nano: int, tokens: array<string, int>, rates: array<string, string>}
     */
    public function estimate(array $data): array
    {
        $referenceImages = $data['reference_images'] ?? [];

        return $this->calculator->estimate(
            $this->client->pricing(),
            $data['prompt'],
            $data['size'],
            $data['quality'],
            $referenceImages,
        );
    }

    /**
     * @param  array{prompt: string, size: string, quality: string, reference_images?: array<int, string>}  $data
     */
    public function create(User $user, array $data): Generation
    {
        $estimate = $this->estimate($data);
        $chargeNano = $estimate['charge_nano'];

        $generation = DB::transaction(function () use ($user, $data, $estimate, $chargeNano): Generation {
            /** @var User $lockedUser */
            $lockedUser = User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            if ($lockedUser->balance_nano < $chargeNano) {
                throw ValidationException::withMessages([
                    'balance' => 'You do not have enough credits for this image. Buy more credits to continue.',
                ]);
            }

            $id = Str::uuid()->toString();

            /** @var Generation $generation */
            $generation = Generation::create([
                'id' => $id,
                'user_id' => $lockedUser->id,
                'idempotency_key' => $id,
                'status' => Generation::STATUS_QUEUED,
                'prompt' => $data['prompt'],
                'size' => $data['size'],
                'quality' => $data['quality'],
                'reference_images' => $data['reference_images'] ?? [],
                'estimated_provider_cost_nano' => $estimate['provider_cost_nano'],
                'estimated_charge_nano' => $estimate['charge_nano'],
                'reserved_nano' => $chargeNano,
            ]);

            $lockedUser->decrement('balance_nano', $chargeNano);

            CreditTransaction::create([
                'user_id' => $lockedUser->id,
                'generation_id' => $generation->id,
                'type' => CreditTransaction::TYPE_RESERVATION,
                'amount_nano' => -$chargeNano,
                'description' => 'Reserved for estimated image cost',
            ]);

            return $generation;
        });

        $payload = [
            'prompt' => $data['prompt'],
            'size' => $data['size'],
            'quality' => $data['quality'],
            'metadata' => ['local_id' => $generation->id],
        ];

        if (! empty($data['reference_images'])) {
            $payload['reference_images'] = array_values($data['reference_images']);
        }

        try {
            /** @var array<string, mixed> $providerGeneration */
            $providerGeneration = $this->client->createGeneration($payload, $generation->idempotency_key);
        } catch (PixelforgeRequestFailed $exception) {
            if ($exception->errorCode === 'connection_error') {
                $this->markSubmissionUncertain($generation, $exception);
            } else {
                $this->failSubmission($generation, $exception);
            }

            throw $exception;
        }

        $this->applyProviderGeneration($generation, $providerGeneration);

        return $generation->fresh() ?? $generation;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function applyWebhookEvent(array $event): void
    {
        /** @var array<string, mixed>|null $object */
        $object = data_get($event, 'data.object');

        if (! is_array($object)) {
            return;
        }

        $localId = data_get($object, 'metadata.local_id');
        $providerId = data_get($object, 'id');

        $generation = null;

        if (is_string($localId) && $localId !== '') {
            $generation = Generation::find($localId);
        }

        if (! $generation && is_string($providerId)) {
            $generation = Generation::where('provider_id', $providerId)->first();
        }

        if (! $generation) {
            return;
        }

        $this->applyProviderGeneration($generation, $object);
    }

    public function sync(Generation $generation): void
    {
        if (! $generation->provider_id || $generation->isTerminal()) {
            return;
        }

        $object = $this->client->generation($generation->provider_id);

        if ($object !== null) {
            $this->applyProviderGeneration($generation, $object);
        }
    }

    public function reconcilePending(): int
    {
        /** @var Collection<int, Generation> $pending */
        $pending = Generation::query()
            ->where('created_at', '<=', now()->subSeconds(30))
            ->where(function ($query): void {
                $query->whereNotIn('status', [Generation::STATUS_SUCCEEDED, Generation::STATUS_FAILED])
                    ->orWhereNull('actual_charge_nano');
            })
            ->orderBy('created_at')
            ->limit(50)
            ->get();

        if ($pending->isEmpty()) {
            return 0;
        }

        $byLocalId = [];
        $byProviderId = [];

        foreach ($this->client->listGenerations() as $object) {
            $providerId = $object['id'] ?? null;
            $localId = data_get($object, 'metadata.local_id');

            if (is_string($providerId)) {
                $byProviderId[$providerId] = $object;
            }

            if (is_string($localId)) {
                $byLocalId[$localId] = $object;
            }
        }

        $reconciled = 0;

        foreach ($pending as $generation) {
            try {
                if ($generation->provider_id !== null) {
                    $object = $byProviderId[$generation->provider_id]
                        ?? $this->client->generation($generation->provider_id);
                } else {
                    $object = $byLocalId[$generation->id] ?? null;
                }

                if (! is_array($object)) {
                    continue;
                }

                $this->applyProviderGeneration($generation, $object);
                $reconciled++;
            } catch (PixelforgeRequestFailed) {
                continue;
            }
        }

        return $reconciled;
    }

    public function purchaseCredits(User $user, int $amountNano): void
    {
        DB::transaction(function () use ($user, $amountNano): void {
            /** @var User $lockedUser */
            $lockedUser = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $lockedUser->increment('balance_nano', $amountNano);

            CreditTransaction::create([
                'user_id' => $lockedUser->id,
                'type' => CreditTransaction::TYPE_PURCHASE,
                'amount_nano' => $amountNano,
                'description' => 'Added credits',
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function applyProviderGeneration(Generation $generation, array $object): void
    {
        DB::transaction(function () use ($generation, $object): void {
            /** @var Generation $current */
            $current = Generation::whereKey($generation->id)->lockForUpdate()->firstOrFail();

            $status = (string) ($object['status'] ?? '');

            if (! in_array($status, [
                Generation::STATUS_QUEUED,
                Generation::STATUS_PROCESSING,
                Generation::STATUS_SUCCEEDED,
                Generation::STATUS_FAILED,
            ], true)) {
                return;
            }

            $incomingRank = $this->statusRank($status);
            $currentRank = $this->statusRank($current->status);

            if ($incomingRank < $currentRank) {
                return;
            }

            if ($current->isTerminal() && $incomingRank === 2 && $status !== $current->status) {
                return;
            }

            $providerId = $object['id'] ?? null;

            if (is_string($providerId) && $providerId !== '' && $current->provider_id === null) {
                $current->provider_id = $providerId;
            }

            $current->status = $status;
            $current->provider_created_at = $this->parseDate($object['created_at'] ?? null)
                ?? $current->provider_created_at;
            $current->provider_started_at = $this->parseDate($object['started_at'] ?? null)
                ?? $current->provider_started_at;
            $current->provider_completed_at = $this->parseDate($object['completed_at'] ?? null)
                ?? $current->provider_completed_at;

            if (is_array($object['rates'] ?? null)) {
                $current->rates = $object['rates'];
            }

            if ($status === Generation::STATUS_SUCCEEDED) {
                $rates = $current->rates;
                $usage = $object['usage'] ?? $current->provider_usage;

                if (is_array($rates) && is_array($usage) && $current->actual_provider_cost_nano === null) {
                    /** @var array<string, string> $rateStrings */
                    $rateStrings = array_map('strval', $rates);
                    $providerCostNano = $this->calculator->actual($rateStrings, $usage);

                    $current->rates = $rates;
                    $current->provider_usage = $usage;
                    $current->actual_provider_cost_nano = $providerCostNano;
                    $current->actual_charge_nano = Money::withMarkup($providerCostNano);
                }

                $current->image_url = $object['image_url'] ?? $current->image_url;
                $current->image_url_expires_at = $this->parseDate($object['image_url_expires_at'] ?? null)
                    ?? $current->image_url_expires_at;
                $current->error_code = null;
                $current->error_message = null;
            }

            if ($status === Generation::STATUS_FAILED) {
                if ($current->actual_provider_cost_nano === null) {
                    $current->actual_provider_cost_nano = 0;
                    $current->actual_charge_nano = 0;
                }

                /** @var array<string, mixed>|null $error */
                $error = $object['error'] ?? null;
                $current->error_code = is_array($error) ? (string) ($error['code'] ?? 'generation_failed') : 'generation_failed';
                $current->error_message = is_array($error)
                    ? (string) ($error['message'] ?? 'Pixelforge could not generate this image. You were not charged.')
                    : 'Pixelforge could not generate this image. You were not charged.';
            }

            if ($current->isTerminal() && $current->actual_charge_nano !== null) {
                $this->settle($current, $current->actual_charge_nano);
            }

            $current->save();
        });
    }

    private function markSubmissionUncertain(Generation $generation, PixelforgeRequestFailed $exception): void
    {
        DB::transaction(function () use ($generation, $exception): void {
            /** @var Generation $current */
            $current = Generation::whereKey($generation->id)->lockForUpdate()->firstOrFail();

            if ($current->isTerminal() || $current->provider_id !== null) {
                return;
            }

            $current->error_code = 'submission_uncertain';
            $current->error_message = $exception->getMessage();
            $current->save();
        });
    }

    private function failSubmission(Generation $generation, PixelforgeRequestFailed $exception): void
    {
        DB::transaction(function () use ($generation, $exception): void {
            /** @var Generation $current */
            $current = Generation::whereKey($generation->id)->lockForUpdate()->firstOrFail();

            if ($current->provider_id !== null || $current->isTerminal()) {
                return;
            }

            $current->status = Generation::STATUS_FAILED;
            $current->actual_provider_cost_nano = 0;
            $current->actual_charge_nano = 0;
            $current->error_code = $exception->errorCode ?? 'submission_failed';
            $current->error_message = $exception->getMessage();

            $this->settle($current, 0);

            $current->save();
        });
    }

    private function settle(Generation $generation, int $actualChargeNano): void
    {
        if ($generation->settled) {
            return;
        }

        $difference = ($generation->reserved_nano ?? 0) - $actualChargeNano;

        if ($difference !== 0) {
            /** @var User $user */
            $user = User::whereKey($generation->user_id)->lockForUpdate()->firstOrFail();
            $description = $difference > 0 ? 'Refund of unused estimate' : 'Additional charge for final cost';

            if ($difference > 0) {
                $user->increment('balance_nano', $difference);
            } else {
                $user->decrement('balance_nano', abs($difference));
            }

            CreditTransaction::create([
                'user_id' => $user->id,
                'generation_id' => $generation->id,
                'type' => $difference > 0 ? CreditTransaction::TYPE_REFUND : CreditTransaction::TYPE_RESERVATION,
                'amount_nano' => $difference,
                'description' => $description,
            ]);
        }

        $generation->settled = true;
    }

    private function statusRank(string $status): int
    {
        return match ($status) {
            Generation::STATUS_QUEUED => 0,
            Generation::STATUS_PROCESSING => 1,
            default => 2,
        };
    }

    private function parseDate(mixed $value): ?CarbonInterface
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
