<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BusinessDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_metrics_and_recent_activity_are_scoped_to_the_active_tenant(): void
    {
        [$user, $tenant] = $this->workspace('dashboard-primary');
        $otherTenant = Tenant::query()->create([
            'name' => 'Other Workspace',
            'slug' => 'dashboard-other',
            'timezone' => 'UTC',
        ]);

        TenantContext::runForTenant($tenant->id, function (): void {
            $customer = Customer::query()->create([
                'whatsapp_wa_id' => '923001112233',
                'display_name' => 'Primary Customer',
            ]);
            Conversation::query()->create([
                'customer_id' => $customer->id,
                'status' => 'open',
                'automation_state' => 'WAITING_FOR_STAFF',
                'last_message_at' => now(),
            ]);
            Service::query()->create([
                'name' => 'Consultation',
                'price_amount' => '100.00',
                'currency' => 'PKR',
                'duration_minutes' => 30,
                'active' => true,
            ]);
            Staff::query()->create(['name' => 'Primary Staff', 'active' => true]);
        });

        TenantContext::runForTenant($otherTenant->id, function (): void {
            foreach (['923009991111', '923009992222', '923009993333'] as $number) {
                Customer::query()->create(['whatsapp_wa_id' => $number]);
            }
            Conversation::query()->create([
                'customer_id' => Customer::query()->firstOrFail()->id,
                'status' => 'open',
                'automation_state' => 'AUTO_ACTIVE',
                'last_message_at' => now(),
            ]);
        });

        config(['services.groq.daily_request_limit' => 250]);
        DB::table('ai_usage_daily')->insert([
            [
                'tenant_id' => $tenant->id,
                'usage_date' => now()->toDateString(),
                'requests' => 7,
                'tokens' => 350,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'tenant_id' => $otherTenant->id,
                'usage_date' => now()->toDateString(),
                'requests' => 99,
                'tokens' => 9999,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        DB::table('ai_usage_events')->insert([
            [
                'tenant_id' => $tenant->id,
                'provider' => 'groq',
                'model' => 'test-model',
                'outcome' => 'succeeded',
                'http_status' => 200,
                'prompt_tokens' => 100,
                'completion_tokens' => 25,
                'created_at' => now(),
            ],
            [
                'tenant_id' => $otherTenant->id,
                'provider' => 'groq',
                'model' => 'test-model',
                'outcome' => 'succeeded',
                'http_status' => 200,
                'prompt_tokens' => 900,
                'completion_tokens' => 900,
                'created_at' => now(),
            ],
        ]);

        $this->actingAs($user)->withSession(['current_tenant_id' => $tenant->id])
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('tenant.id', $tenant->id)
                ->where('tenant.name', 'Primary Workspace')
                ->where('metrics.customers', 1)
                ->where('metrics.open_conversations', 1)
                ->where('metrics.waiting_for_staff', 1)
                ->where('metrics.active_services', 1)
                ->where('metrics.active_staff', 1)
                ->where('ai_usage.available', true)
                ->where('ai_usage.today_available', true)
                ->where('ai_usage.today_requests', 7)
                ->where('ai_usage.today_tokens', 350)
                ->where('ai_usage.daily_request_limit', 250)
                ->where('ai_usage.attempts_30d', 1)
                ->where('ai_usage.tokens_30d', 125)
                ->where('integrations.whatsapp', false)
                ->where('integrations.google_calendar', false)
                ->has('recent_conversations', 1)
                ->where('recent_conversations.0.customer', 'Primary Customer')
            );
    }

    private function workspace(string $slug): array
    {
        $user = User::query()->create([
            'name' => 'Dashboard Admin',
            'email' => $slug.'@example.test',
            'password' => 'StrongPassword123',
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        $tenant = Tenant::query()->create([
            'name' => 'Primary Workspace',
            'slug' => $slug,
            'timezone' => 'Asia/Karachi',
        ]);
        $tenant->users()->attach($user->id, ['role' => 'business_admin', 'status' => 'active']);

        return [$user, $tenant];
    }
}
