<?php

namespace Tests\Feature;

use App\Models\Staff;
use App\Models\StaffWorkingHour;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffWorkingHoursTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_admin_can_save_seven_days_for_a_staff_member(): void
    {
        [$user, $tenant] = $this->workspace('staff-hours-admin@example.test');
        $staff = TenantContext::runForTenant($tenant->id, fn () => Staff::query()->create([
            'name' => 'Team Member',
            'active' => true,
        ]));
        $this->actingAs($user)->withSession(['current_tenant_id' => $tenant->id]);

        $days = [];
        for ($weekday = 0; $weekday < 7; $weekday++) {
            $days[] = [
                'weekday' => $weekday,
                'is_closed' => $weekday === 6,
                'opens_at' => '09:00',
                'closes_at' => '17:00',
            ];
        }

        $this->put('/business/staff/'.$staff->id.'/hours', ['days' => $days])->assertRedirect();
        $this->assertDatabaseCount('staff_working_hours', 7);
        $this->assertDatabaseHas('staff_working_hours', [
            'tenant_id' => $tenant->id,
            'staff_id' => $staff->id,
            'weekday' => 6,
            'is_closed' => true,
            'opens_at' => null,
            'closes_at' => null,
        ]);
    }

    public function test_staff_working_hours_reject_invalid_time_ranges(): void
    {
        [$user, $tenant] = $this->workspace('staff-hours-invalid@example.test');
        $staff = TenantContext::runForTenant($tenant->id, fn () => Staff::query()->create([
            'name' => 'Team Member',
            'active' => true,
        ]));
        $this->actingAs($user)->withSession(['current_tenant_id' => $tenant->id]);

        $days = [];
        for ($weekday = 0; $weekday < 7; $weekday++) {
            $days[] = [
                'weekday' => $weekday,
                'is_closed' => false,
                'opens_at' => '09:00',
                'closes_at' => '17:00',
            ];
        }
        $days[2]['closes_at'] = '08:00';

        $this->put('/business/staff/'.$staff->id.'/hours', ['days' => $days])
            ->assertSessionHasErrors('days.2.closes_at');
        $this->assertDatabaseCount('staff_working_hours', 0);
    }

    public function test_staff_working_hours_are_tenant_scoped(): void
    {
        [, $tenantA] = $this->workspace('staff-hours-a@example.test');
        [, $tenantB] = $this->workspace('staff-hours-b@example.test');
        $staffA = TenantContext::runForTenant($tenantA->id, fn () => Staff::query()->create([
            'name' => 'Tenant A staff',
            'active' => true,
        ]));

        TenantContext::runForTenant($tenantA->id, function () use ($staffA): void {
            StaffWorkingHour::query()->create([
                'staff_id' => $staffA->id,
                'weekday' => 0,
                'is_closed' => false,
                'opens_at' => '09:00',
                'closes_at' => '17:00',
            ]);
        });
        TenantContext::runForTenant($tenantB->id, function (): void {
            $this->assertSame(0, StaffWorkingHour::query()->count());
        });
    }

    public function test_staff_role_cannot_manage_working_hours(): void
    {
        [$user, $tenant] = $this->workspace('staff-hours-role@example.test', 'staff');
        $staff = TenantContext::runForTenant($tenant->id, fn () => Staff::query()->create([
            'name' => 'Team Member',
            'active' => true,
        ]));
        $this->actingAs($user)->withSession(['current_tenant_id' => $tenant->id]);

        $this->get('/business/staff/'.$staff->id.'/hours')->assertForbidden();
    }


    public function test_staff_route_binding_cannot_resolve_another_tenants_member(): void
    {
        [, $tenantA] = $this->workspace('staff-hours-owner-a@example.test');
        [$userB, $tenantB] = $this->workspace('staff-hours-owner-b@example.test');
        $staffA = TenantContext::runForTenant($tenantA->id, function (): Staff {
            $staff = Staff::query()->create([
                'name' => 'Private staff member',
                'active' => true,
            ]);
            StaffWorkingHour::query()->create([
                'staff_id' => $staff->id,
                'weekday' => 0,
                'is_closed' => false,
                'opens_at' => '09:00',
                'closes_at' => '17:00',
            ]);

            return $staff;
        });

        $days = [];
        for ($weekday = 0; $weekday < 7; $weekday++) {
            $days[] = [
                'weekday' => $weekday,
                'is_closed' => true,
                'opens_at' => null,
                'closes_at' => null,
            ];
        }

        $this->actingAs($userB)->withSession(['current_tenant_id' => $tenantB->id]);
        $this->get('/business/staff/'.$staffA->id.'/hours')->assertNotFound();
        $this->put('/business/staff/'.$staffA->id.'/hours', ['days' => $days])->assertNotFound();

        $this->assertDatabaseHas('staff_working_hours', [
            'tenant_id' => $tenantA->id,
            'staff_id' => $staffA->id,
            'weekday' => 0,
            'is_closed' => false,
            'opens_at' => '09:00',
            'closes_at' => '17:00',
        ]);
        $this->assertDatabaseCount('staff_working_hours', 1);
    }

    private function workspace(string $email, string $role = 'business_admin'): array
    {
        $user = User::query()->create([
            'name' => 'Staff Hours User',
            'email' => $email,
            'password' => 'StrongPassword123',
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $tenant = Tenant::query()->create([
            'name' => 'Staff Hours Workspace',
            'slug' => str_replace(['@', '.'], '-', $email),
        ]);
        $tenant->users()->attach($user->id, ['role' => $role, 'status' => 'active']);

        return [$user, $tenant];
    }
}
