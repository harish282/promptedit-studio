<?php

namespace App\Http\Controllers;

use App\Exceptions\PixelforgeRequestFailed;
use App\Models\Generation;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pricing\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class AdminReportController extends Controller
{
    public function index(Request $request, PixelforgeClient $client): Response
    {
        $endDate = Carbon::today('UTC');
        $startDate = $endDate->copy()->subDays(29);

        $rows = Generation::query()
            ->join('users', 'users.id', '=', 'generations.user_id')
            ->where('generations.status', Generation::STATUS_SUCCEEDED)
            ->whereNotNull('generations.provider_completed_at')
            ->whereBetween('generations.provider_completed_at', [
                $startDate->copy()->startOfDay(),
                $endDate->copy()->endOfDay(),
            ])
            ->selectRaw(
                'DATE(generations.provider_completed_at) as date, '
                .'users.id as user_id, users.name as user_name, users.email as user_email, '
                .'COUNT(*) as generations_count, '
                .'SUM(generations.actual_provider_cost_nano) as provider_cost_nano, '
                .'SUM(generations.actual_charge_nano) as charge_nano, '
                .'SUM(generations.actual_charge_nano - generations.actual_provider_cost_nano) as profit_nano'
            )
            ->groupBy('date', 'users.id', 'users.name', 'users.email')
            ->orderByDesc('date')
            ->orderBy('user_name')
            ->get()
            ->map(fn ($row): array => [
                'date' => $row->date,
                'user_id' => $row->user_id,
                'user_name' => $row->user_name,
                'user_email' => $row->user_email,
                'generations' => (int) $row->generations_count,
                'provider_cost_nano' => (int) $row->provider_cost_nano,
                'charge_nano' => (int) $row->charge_nano,
                'profit_nano' => (int) $row->profit_nano,
                'provider_cost_usd' => Money::toDollars((int) $row->provider_cost_nano),
                'charge_usd' => Money::toDollars((int) $row->charge_nano),
                'profit_usd' => Money::toDollars((int) $row->profit_nano),
            ])
            ->all();

        $daily = collect($rows)
            ->groupBy('date')
            ->map(fn ($group, string $date): array => [
                'date' => $date,
                'generations' => $group->sum('generations'),
                'provider_cost_usd' => Money::toDollars($group->sum('provider_cost_nano')),
                'charge_usd' => Money::toDollars($group->sum('charge_nano')),
                'profit_usd' => Money::toDollars($group->sum('profit_nano')),
            ])
            ->values()
            ->all();

        $providerUsage = null;
        $usageError = null;

        try {
            $providerUsage = $client->usage(
                $startDate->toDateString(),
                $endDate->toDateString(),
            );
        } catch (PixelforgeRequestFailed $exception) {
            $usageError = $exception->getMessage();
        }

        return Inertia::render('Admin/Report', [
            'rows' => $rows,
            'daily' => $daily,
            'providerUsage' => $providerUsage,
            'usageError' => $usageError,
            'startDate' => $startDate->toDateString(),
            'endDate' => $endDate->toDateString(),
        ]);
    }
}
