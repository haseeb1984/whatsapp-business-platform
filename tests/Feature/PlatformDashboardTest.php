<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\Customer;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
