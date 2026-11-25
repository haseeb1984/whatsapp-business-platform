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

        $this->artisan('ai-usage:prune')->assertSuccessful();

        $this->assertDatabaseCount('ai_usage_events', 1);
        $this->assertDatabaseHas('ai_usage_events', [
            'tenant_id' => $tenant->id,
            'created_at' => now()->subDays(89)->toDateTimeString(),
        ]);
    }

    public function test_prune_command_enforces_a_minimum_thirty_day_retention(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Usage Retention Bounds', 'slug' => 'usage-retention-bounds']);
        $this->insertEvent($tenant->id, now()->subDays(31));
        $this->insertEvent($tenant->id, now()->subDays(20));

        $this->artisan('ai-usage:prune', ['--days' => 1])->assertSuccessful();

        $this->assertDatabaseCount('ai_usage_events', 1);
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
