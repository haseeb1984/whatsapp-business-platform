<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\Staff;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_admin_can_create_update_and_remove_staff_with_service_assignments(): void
    {
        [$user, $tenant] = $this->workspace('staff-admin@example.test');
        $service = $this->createService($tenant->id, 'Haircut');
        $this->actingAs($user)->withSession(['current_tenant_id' => $tenant->id]);

        $payload = [
            'name' => 'Ayesha Khan',
            'email' => 'ayesha@example.test',
            'phone' => '+923001112233',
            'active' => true,
            'service_ids' => [$service->id],
        ];

        $this->post('/business/staff', $payload)->assertRedirect();
        $member = TenantContext::runForTenant($tenant->id, fn () => Staff::query()->firstOrFail());
        $this->assertDatabaseHas('staff_services', [
            'tenant_id' => $tenant->id,
            'staff_id' => $member->id,
            'service_id' => $service->id,
        ]);

        $this->put('/business/staff/'.$member->id, [...$payload, 'name' => 'Ayesha Updated', 'service_ids' => []])->assertRedirect();
        $this->assertDatabaseHas('staff_members', ['id' => $member->id, 'name' => 'Ayesha Updated']);
        $this->assertDatabaseMissing('staff_services', ['staff_id' => $member->id]);

        $this->delete('/business/staff/'.$member->id)->assertRedirect();
        $this->assertDatabaseMissing('staff_members', ['id' => $member->id]);
    }

    public function test_staff_cannot_be_assigned_a_service_from_another_tenant(): void
    {
        [, $tenantA] = $this->workspace('staff-a@example.test');
        [$userB, $tenantB] = $this->workspace('staff-b@example.test');
        $foreignService = $this->createService($tenantA->id, 'Private service');

        $this->actingAs($userB)->withSession(['current_tenant_id' => $tenantB->id]);
        $this->post('/business/staff', [
            'name' => 'Cross tenant attempt',
            'email' => null,
            'phone' => null,
            'active' => true,
            'service_ids' => [$foreignService->id],
        ])->assertSessionHasErrors('service_ids.0');

        $this->assertDatabaseCount('staff_members', 0);
        $this->assertDatabaseCount('staff_services', 0);
    }

    public function test_staff_records_are_isolated_between_tenants(): void
    {
        [, $tenantA] = $this->workspace('staff-isolation-a@example.test');
        [, $tenantB] = $this->workspace('staff-isolation-b@example.test');

        TenantContext::runForTenant($tenantA->id, function (): void {
            Staff::query()->create(['name' => 'Tenant A staff', 'active' => true]);
        });
        TenantContext::runForTenant($tenantB->id, function (): void {
            $this->assertSame(0, Staff::query()->count());
        });
    }

    public function test_staff_role_cannot_manage_team_members(): void
    {
        [$user, $tenant] = $this->workspace('staff-role@example.test', 'staff');
        $this->actingAs($user)->withSession(['current_tenant_id' => $tenant->id]);

        $this->get('/business/staff')->assertForbidden();
        $this->post('/business/staff', [])->assertForbidden();
    }

    private function createService(string $tenantId, string $name): Service
    {
        return TenantContext::runForTenant($tenantId, fn () => Service::query()->create([
            'name' => $name,
            'description' => null,
            'price_amount' => '500.00',
            'currency' => 'PKR',
            'duration_minutes' => 30,
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 0,
            'active' => true,
        ]));
    }

    private function workspace(string $email, string $role = 'business_admin'): array
    {
        $user = User::query()->create([
            'name' => 'Staff Test User',
            'email' => $email,
            'password' => 'StrongPassword123',
                    ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $tenant = Tenant::query()->create([
            'name' => 'Staff Test Workspace',
            'slug' => str_replace(['@', '.'], '-', $email),
        ]);
        $tenant->users()->attach($user->id, ['role' => $role, 'status' => 'active']);

        return [$user, $tenant];
    }
}
