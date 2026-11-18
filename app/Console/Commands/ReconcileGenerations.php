<?php

namespace App\Console\Commands;

use App\Exceptions\PixelforgeRequestFailed;
use App\Services\Generations\GenerationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:reconcile-generations')]
#[Description('Sync pending image generations with Pixelforge as a fallback for missed webhooks.')]
class ReconcileGenerations extends Command
{
    public function handle(GenerationService $service): int
    {
        try {
            $count = $service->reconcilePending();
        } catch (PixelforgeRequestFailed $exception) {
            $this->warn($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Reconciled {$count} generation(s).");

        return self::SUCCESS;
    }
}
