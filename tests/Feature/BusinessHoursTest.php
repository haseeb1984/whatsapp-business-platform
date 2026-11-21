<?php

namespace Tests\Feature;

use App\Models\BusinessHour;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessHoursTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_admin_can_save_all_seven_days(): void
    {
        [$user, $tenant] = $this->workspace('hours-admin@example.test');
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

        $this->put('/business/hours', ['days' => $days])->assertRedirect();
        $this->assertDatabaseCount('business_hours', 7);
        $this->assertDatabaseHas('business_hours', [
            'tenant_id' => $tenant->id,
            'weekday' => 6,
            'is_closed' => true,
            'opens_at' => null,
            'closes_at' => null,
        ]);
        $this->assertDatabaseHas('business_hours', [
            'tenant_id' => $tenant->id,
            'weekday' => 0,
            'is_closed' => false,
            'opens_at' => '09:00:00',
            'closes_at' => '17:00:00',
        ]);
    }

    public function test_business_hours_reject_duplicate_days_and_invalid_time_ranges(): void
    {
        [$user, $tenant] = $this->workspace('hours-invalid@example.test');
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
        $days[6]['weekday'] = 5;
        $days[0]['closes_at'] = '08:00';

        $this->put('/business/hours', ['days' => $days])
            ->assertSessionHasErrors(['days', 'days.0.closes_at']);
        $this->assertDatabaseCount('business_hours', 0);
    }

    public function test_business_hours_are_isolated_between_tenants(): void
    {
        [, $tenantA] = $this->workspace('hours-a@example.test');
        [, $tenantB] = $this->workspace('hours-b@example.test');

        TenantContext::runForTenant($tenantA->id, function (): void {
            BusinessHour::query()->create([
                'weekday' => 0,
                'is_closed' => false,
                'opens_at' => '08:00',
                'closes_at' => '16:00',
            ]);
        });
        TenantContext::runForTenant($tenantB->id, function (): void {
            $this->assertSame(0, BusinessHour::query()->count());
            BusinessHour::query()->create([
                'weekday' => 0,
                'is_closed' => false,
                'opens_at' => '10:00',
                'closes_at' => '18:00',
            ]);
            $this->assertSame('10:00:00', BusinessHour::query()->firstOrFail()->opens_at);
        });
    }

    public function test_staff_cannot_read_or_update_business_hours(): void
    {
        [$user, $tenant] = $this->workspace('hours-staff@example.test', 'staff');
        $this->actingAs($user)->withSession(['current_tenant_id' => $tenant->id]);

        $this->get('/business/hours')->assertForbidden();
        $this->put('/business/hours', ['days' => []])->assertForbidden();
    }

    private function workspace(string $email, string $role = 'business_admin'): array
    {
        $user = User::query()->create([
            'name' => 'Hours User',
            'email' => $email,
            'password' => 'StrongPassword123',
                    ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $tenant = Tenant::query()->create([
            'name' => 'Hours Workspace',
            'slug' => str_replace(['@', '.'], '-', $email),
        ]);
        $tenant->users()->attach($user->id, ['role' => $role, 'status' => 'active']);

        return [$user, $tenant];
    }
}
