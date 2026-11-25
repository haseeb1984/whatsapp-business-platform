<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AiUsagePruningCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_prune_command_removes_events_past_the_configured_retention_window(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Usage Retention', 'slug' => 'usage-retention']);
        $this->insertEvent($tenant->id, now()->subDays(91));
        $this->insertEvent($tenant->id, now()->subDays(89));
        $this->insertDailyCounter($tenant->id, now()->subDays(401));
        $this->insertDailyCounter($tenant->id, now()->subDays(399));

        $this->artisan('ai-usage:prune')->assertSuccessful();

        $this->assertDatabaseCount('ai_usage_events', 1);
        $this->assertDatabaseCount('ai_usage_daily', 1);
        $daily = DB::table('ai_usage_daily')->where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($daily);
        $this->assertGreaterThan(now()->subDays(400)->toDateString(), $daily->usage_date);
        $remaining = DB::table('ai_usage_events')->where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($remaining);
        $this->assertTrue(\Illuminate\Support\Carbon::parse($remaining->created_at)->greaterThan(now()->subDays(90)));
    }

    public function test_prune_command_enforces_a_minimum_thirty_day_retention(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Usage Retention Bounds', 'slug' => 'usage-retention-bounds']);
        $this->insertEvent($tenant->id, now()->subDays(31));
        $this->insertEvent($tenant->id, now()->subDays(20));

        $this->artisan('ai-usage:prune', ['--days' => 1])->assertSuccessful();

        $this->assertDatabaseCount('ai_usage_events', 1);
    }

    private function insertDailyCounter(string $tenantId, \Illuminate\Support\Carbon $usageDate): void
    {
        DB::table('ai_usage_daily')->insert([
            'tenant_id' => $tenantId,
            'usage_date' => $usageDate->toDateString(),
            'requests' => 10,
            'tokens' => 100,
            'created_at' => $usageDate,
            'updated_at' => $usageDate,
        ]);
    }

    private function insertEvent(string $tenantId, \Illuminate\Support\Carbon $createdAt): void
    {
        DB::table('ai_usage_events')->insert([
            'tenant_id' => $tenantId,
            'provider' => 'groq',
            'model' => 'test-model',
            'outcome' => 'succeeded',
            'http_status' => 200,
            'prompt_tokens' => 10,
            'completion_tokens' => 5,
            'created_at' => $createdAt,
        ]);
    }
}
