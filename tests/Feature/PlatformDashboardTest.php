<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\Customer;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use App\Support\Tenancy\TenantContext;
use Tests\TestCase;

class PlatformDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_allowlisted_verified_users_can_view_global_platform_operations(): void
    {
        config(['platform.admin_emails' => ['platform-admin@example.test']]);
        $admin = $this->user('platform-admin@example.test');
        $tenant = Tenant::query()->create([
            'name' => 'Salon Workspace',
            'slug' => 'platform-salon',
            'timezone' => 'Asia/Karachi',
            'status' => 'active',
        ]);
        TenantContext::runForTenant($tenant->id, function (): void {
            $customer = Customer::query()->create(['whatsapp_wa_id' => '923001112233']);
            $conversation = Conversation::query()->create([
                'customer_id' => $customer->id,
                'status' => 'open',
                'automation_state' => 'AUTO_ACTIVE',
            ]);

            foreach (['pending', 'sending', 'unknown', 'failed'] as $status) {
                Message::query()->create([
                    'conversation_id' => $conversation->id,
                    'direction' => 'outbound',
                    'message_type' => 'text',
                    'body' => 'Operational status sample',
                    'delivery_status' => $status,
                ]);
            }
        });

        $this->actingAs($admin)->get('/platform')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Platform/Dashboard')
                ->where('metrics.tenants_total', 1)
                ->where('metrics.tenants_active', 1)
                ->where('metrics.users_total', 1)
                ->where('metrics.outbound_pending', 1)
                ->where('metrics.outbound_sending', 1)
                ->where('metrics.outbound_unknown', 1)
                ->where('metrics.outbound_failed', 1)
                ->has('tenants.data', 1)
                ->where('tenants.data.0.name', 'Salon Workspace')
            );
    }

    public function test_platform_console_reports_queue_backlog_age_and_recent_failures_without_job_payloads(): void
    {
        config(['platform.admin_emails' => ['platform-admin@example.test']]);
        $admin = $this->user('platform-admin@example.test');

        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{"displayName":"SensitiveJobPayload"}',
            'attempts' => 1,
            'reserved_at' => null,
            'available_at' => now()->subMinutes(17)->timestamp,
            'created_at' => now()->subMinutes(17)->timestamp,
        ]);
        DB::table('failed_jobs')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{"displayName":"SensitiveJobPayload"}',
            'exception' => 'Sensitive exception details',
            'failed_at' => now()->subHours(2),
        ]);

        $this->actingAs($admin)->get('/platform')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('metrics.queue_pending', 1)
                ->where('metrics.queue_oldest_age_minutes', 17)
                ->where('metrics.queue_failed', 1)
                ->where('metrics.queue_failed_last_24_hours', 1)
                ->has('recent_failures', 1)
                ->missing('recent_failures.0.payload')
                ->missing('recent_failures.0.exception')
            );
    }

    public function test_non_allowlisted_user_is_forbidden_from_platform_console(): void
    {
        config(['platform.admin_emails' => ['platform-admin@example.test']]);
        $user = $this->user('business-owner@example.test');

        $this->actingAs($user)->get('/platform')->assertForbidden();
    }


    public function test_platform_tenant_search_filters_and_csv_export_are_available(): void
    {
        config(['platform.admin_emails' => ['platform-admin@example.test']]);
        $admin = $this->user('platform-admin@example.test');
        Tenant::query()->create(['name' => 'Salon North', 'slug' => 'salon-north', 'status' => 'active']);
        Tenant::query()->create(['name' => 'Clinic South', 'slug' => 'clinic-south', 'status' => 'suspended']);

        $this->actingAs($admin)->get('/platform?q=salon&status=active')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.q', 'salon')
                ->where('filters.status', 'active')
                ->where('tenants.total', 1)
                ->where('tenants.data.0.name', 'Salon North')
            );

        $this->actingAs($admin)->get('/platform/tenants/export?q=salon')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertDownload();
    }

    public function test_tenant_csv_export_neutralizes_spreadsheet_formula_cells(): void
    {
        config(['platform.admin_emails' => ['platform-admin@example.test']]);
        $admin = $this->user('platform-admin@example.test');

        Tenant::query()->create([
            'name' => '=1+1',
            'slug' => '@SUM(1,1)',
            'status' => 'active',
            'timezone' => '+cmd|/C calc',
        ]);

        $response = $this->actingAs($admin)->get('/platform/tenants/export');

        $response->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringContainsString("'=1+1", $csv);
        $this->assertStringContainsString("'@SUM(1,1)", $csv);
        $this->assertStringContainsString("'+cmd|/C calc", $csv);
    }


    public function test_platform_console_reports_integration_authorization_health_without_exposing_credentials(): void
    {
        config(['platform.admin_emails' => ['platform-admin@example.test']]);
        $admin = $this->user('platform-admin@example.test');
        $tenant = Tenant::query()->create([
            'name' => 'Integration Health Workspace',
            'slug' => 'integration-health',
            'status' => 'active',
        ]);

        DB::table('whatsapp_connections')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'tenant_id' => $tenant->id,
            'phone_number_id' => 'health-phone-1',
            'waba_id' => 'health-waba-1',
            'access_token' => 'never-return-this-meta-token',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('google_calendar_connections')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'tenant_id' => $tenant->id,
            'google_account_email' => 'calendar@example.test',
            'access_token' => 'never-return-this-google-token',
            'refresh_token' => null,
            'token_expires_at' => now()->subHour(),
            'scope' => 'https://www.googleapis.com/auth/calendar',
            'default_calendar_id' => 'primary',
            'status' => 'connected',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)->get('/platform')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('metrics.whatsapp_pending', 1)
                ->where('metrics.whatsapp_inactive', 1)
                ->where('metrics.calendar_reauthorization_required', 0)
                ->where('metrics.calendar_expired_tokens', 1)
                ->where('metrics.calendar_missing_refresh_tokens', 1)
                ->missing('metrics.access_token')
                ->missing('metrics.refresh_token')
            );
    }

    public function test_platform_dashboard_aggregates_ai_usage_without_exposing_customer_content(): void
    {
        config(['platform.admin_emails' => ['platform-admin@example.test']]);
        $admin = $this->user('platform-admin@example.test');
        $tenantA = Tenant::query()->create([
            'name' => 'AI Tenant A',
            'slug' => 'ai-tenant-a',
            'created_at' => now()->subMinutes(2),
            'updated_at' => now()->subMinutes(2),
        ]);
        $tenantB = Tenant::query()->create([
            'name' => 'AI Tenant B',
            'slug' => 'ai-tenant-b',
        ]);
        DB::table('tenants')->where('id', $tenantA->id)->update(['created_at' => now()->subMinutes(2)]);
        DB::table('tenants')->where('id', $tenantB->id)->update(['created_at' => now()->subMinute()]);

        DB::table('ai_usage_events')->insert([
            [
                'tenant_id' => $tenantA->id,
                'provider' => 'groq',
                'model' => 'test-model',
                'outcome' => 'succeeded',
                'http_status' => 200,
                'duration_ms' => 120,
                'prompt_tokens' => 100,
                'completion_tokens' => 50,
                'created_at' => now()->subMinutes(5),
            ],
            [
                'tenant_id' => $tenantB->id,
                'provider' => 'groq',
                'model' => 'test-model',
                'outcome' => 'rate_limited',
                'http_status' => 429,
                'duration_ms' => 20,
                'prompt_tokens' => null,
                'completion_tokens' => null,
                'created_at' => now()->subMinutes(4),
            ],
            [
                'tenant_id' => $tenantA->id,
                'provider' => 'groq',
                'model' => 'test-model',
                'outcome' => 'succeeded',
                'http_status' => 200,
                'duration_ms' => 100,
                'prompt_tokens' => 999,
                'completion_tokens' => 999,
                'created_at' => now()->subDays(31),
            ],
        ]);

        DB::table('ai_usage_events')->insert([
            'tenant_id' => $tenantA->id,
            'provider' => 'groq',
            'model' => 'test-model',
            'outcome' => 'quota_exceeded',
            'created_at' => now()->subMinutes(3),
        ]);
        DB::table('ai_usage_daily')->insert([
            [
                'tenant_id' => $tenantA->id,
                'usage_date' => now()->toDateString(),
                'requests' => 2,
                'tokens' => 150,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'tenant_id' => $tenantB->id,
                'usage_date' => now()->toDateString(),
                'requests' => 3,
                'tokens' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->actingAs($admin)->get('/platform')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('metrics.ai_attempts_last_30_days', 3)
                ->where('metrics.ai_tokens_last_30_days', 150)
                ->where('metrics.ai_failures_last_24_hours', 2)
                ->where('metrics.ai_rate_limits_last_24_hours', 1)
                ->where('metrics.ai_quota_blocks_last_24_hours', 1)
                ->where('metrics.ai_requests_today', 5)
                ->where('metrics.ai_tokens_today', 150)
                ->where('metrics.ai_provider_attempts_7d', 2)
                ->where('metrics.ai_provider_success_rate_7d', 50)
                ->where('metrics.ai_provider_avg_latency_ms_7d', 70)
                ->where('tenants.data.0.name', 'AI Tenant B')
                ->where('tenants.data.0.ai_attempts_30d', 1)
                ->where('tenants.data.0.ai_tokens_30d', 0)
                ->where('tenants.data.1.name', 'AI Tenant A')
                ->where('tenants.data.1.ai_attempts_30d', 2)
                ->where('tenants.data.1.ai_tokens_30d', 150)
                ->missing('metrics.prompt')
                ->missing('metrics.customer_message')
                ->has('alerts', 0)
            );
    }

    public function test_platform_dashboard_emits_threshold_alerts_only_after_configured_signal_levels(): void
    {
        config([
            'platform.admin_emails' => ['platform-admin@example.test'],
            'services.groq.alerts.provider_min_attempts' => 10,
            'services.groq.alerts.provider_success_rate_percent' => 90,
            'services.groq.alerts.provider_avg_latency_ms' => 3000,
            'services.groq.alerts.rate_limit_count' => 5,
            'services.groq.alerts.queue_oldest_age_minutes' => 10,
            'services.groq.alerts.failed_jobs_24h' => 5,
        ]);
        $admin = $this->user('platform-admin@example.test');
        $tenant = Tenant::query()->create([
            'name' => 'Alert Workspace',
            'slug' => 'alert-workspace',
            'status' => 'active',
        ]);

        $events = [];
        for ($i = 0; $i < 10; $i++) {
            $events[] = [
                'tenant_id' => $tenant->id,
                'provider' => 'groq',
                'model' => 'test-model',
                'outcome' => $i < 5 ? 'succeeded' : 'rate_limited',
                'http_status' => $i < 5 ? 200 : 429,
                'duration_ms' => 4000,
                'prompt_tokens' => $i < 5 ? 100 : null,
                'completion_tokens' => $i < 5 ? 50 : null,
                'created_at' => now()->subMinutes(5),
            ];
        }
        DB::table('ai_usage_events')->insert($events);
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{"displayName":"QueuedJob"}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->subMinutes(20)->timestamp,
            'created_at' => now()->subMinutes(20)->timestamp,
        ]);
        $failedJobs = [];
        for ($i = 0; $i < 5; $i++) {
            $failedJobs[] = [
                'uuid' => (string) \\Illuminate\\Support\\Str::uuid(),
                'connection' => 'database',
                'queue' => 'default',
                'payload' => '{"displayName":"FailedJob"}',
                'exception' => 'Sanitized test exception',
                'failed_at' => now()->subHours(1),
            ];
        }
        DB::table('failed_jobs')->insert($failedJobs);

        $this->actingAs($admin)->get('/platform')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('metrics.ai_provider_attempts_7d', 10)
                ->where('metrics.ai_provider_success_rate_7d', 50)
                ->where('metrics.ai_provider_avg_latency_ms_7d', 4000)
                ->where('metrics.ai_rate_limits_last_24_hours', 5)
                ->has('alerts', 5)
                ->where('alerts.0.code', 'ai_provider_success_rate_low')
                ->where('alerts.1.code', 'ai_provider_latency_high')
                ->where('alerts.2.code', 'ai_rate_limits_high')
                ->where('alerts.3.code', 'queue_backlog_old')
                ->where('alerts.3.severity', 'critical')
                ->where('alerts.4.code', 'failed_jobs_high')
            );
    }

    private function user(string $email): User
    {
        $user = User::query()->create([
            'name' => 'Platform User',
            'email' => $email,
            'password' => 'StrongPassword123',
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }
}
