<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminReportTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.pixelforge.url', 'https://pixelforge.promptedit.dev/v1');
        config()->set('services.pixelforge.key', 'pf_test');
    }

    public function test_forbids_non_admin_users(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/admin/report')->assertForbidden();
    }

    public function test_report_shows_profit_and_reconciles_provider_usage(): void
    {
        $this->travelTo('2026-11-17 12:00:00');
        Http::preventStrayRequests();
        Http::fake([
            'https://pixelforge.promptedit.dev/v1/usage*' => Http::response([
                'object' => 'usage',
                'start_date' => '2026-10-19',
                'end_date' => '2026-11-17',
                'data' => [[
                    'date' => '2026-11-17',
                    'generations' => 1,
                    'input_text_tokens' => 12,
                    'input_image_tokens' => 0,
                    'output_tokens' => 1100,
                    'reasoning_tokens' => 200,
                    'cost_usd' => '0.007200000',
                ]],
                'total' => [
                    'generations' => 1,
                    'cost_usd' => '0.007200000',
                ],
            ]),
        ]);

        $admin = User::factory()->create(['is_admin' => true]);
        $customer = User::factory()->create(['name' => 'Customer One']);
        Generation::factory()->for($customer)->succeeded()->create();

        $this->actingAs($admin)->get('/admin/report')->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Report')
                ->where('rows.0.user_email', $customer->email)
                ->where('rows.0.charge_usd', '0.009000000')
                ->where('rows.0.provider_cost_usd', '0.007200000')
                ->where('rows.0.profit_usd', '0.001800000')
                ->where('daily.0.date', '2026-11-17')
                ->where('providerUsage.total.cost_usd', '0.007200000'));
    }
}
