<?php

namespace Tests\Feature;

use App\Models\BusinessHoliday;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessHolidayTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_admin_can_create_update_and_remove_a_holiday(): void
    {
        [$user, $tenant] = $this->workspace('holiday-admin@example.test');
        $this->actingAs($user)->withSession(['current_tenant_id' => $tenant->id]);

        $payload = [
            'date' => '2026-12-25',
            'name' => 'Christmas Day',
            'is_closed' => true,
            'opens_at' => null,
            'closes_at' => null,
        ];

        $this->post('/business/holidays', $payload)->assertRedirect();
        $holiday = BusinessHoliday::query()->firstOrFail();
        $this->assertSame('Christmas Day', $holiday->name);
        $this->assertTrue($holiday->is_closed);

        $this->put('/business/holidays/'.$holiday->id, [
            ...$payload,
            'name' => 'Christmas special hours',
            'is_closed' => false,
            'opens_at' => '10:00',
            'closes_at' => '14:00',
        ])->assertRedirect();

        $this->assertDatabaseHas('business_holidays', [
            'id' => $holiday->id,
            'tenant_id' => $tenant->id,
            'is_closed' => false,
            'opens_at' => '10:00:00',
            'closes_at' => '14:00:00',
        ]);

        $this->delete('/business/holidays/'.$holiday->id)->assertRedirect();
        $this->assertDatabaseMissing('business_holidays', ['id' => $holiday->id]);
    }

    public function test_holiday_rejects_invalid_special_hours_and_duplicate_dates(): void
    {
        [$user] = $this->workspace('holiday-validation@example.test');
        $this->actingAs($user);

        $payload = [
            'date' => '2026-12-31',
            'name' => 'New Year Eve',
            'is_closed' => false,
            'opens_at' => '18:00',
            'closes_at' => '09:00',
        ];

        $this->post('/business/holidays', $payload)->assertSessionHasErrors('closes_at');
        $this->assertDatabaseCount('business_holidays', 0);

        $payload['is_closed'] = true;
        $payload['opens_at'] = null;
        $payload['closes_at'] = null;
        $this->post('/business/holidays', $payload)->assertRedirect();
        $this->post('/business/holidays', $payload)->assertSessionHasErrors('date');
        $this->assertDatabaseCount('business_holidays', 1);
    }

    public function test_holidays_are_isolated_between_tenants(): void
    {
        [, $tenantA] = $this->workspace('holiday-a@example.test');
        [, $tenantB] = $this->workspace('holiday-b@example.test');

        TenantContext::runForTenant($tenantA->id, function (): void {
            BusinessHoliday::query()->create([
                'date' => '2026-12-25',
                'name' => 'Tenant A closure',
                'is_closed' => true,
            ]);
        });

        TenantContext::runForTenant($tenantB->id, function (): void {
            $this->assertSame(0, BusinessHoliday::query()->count());
        });
    }

    public function test_staff_cannot_manage_holidays(): void
    {
        [$user, $tenant] = $this->workspace('holiday-staff@example.test', 'staff');
        $this->actingAs($user)->withSession(['current_tenant_id' => $tenant->id]);

        $this->get('/business/holidays')->assertForbidden();
        $this->post('/business/holidays', [])->assertForbidden();
    }

    private function workspace(string $email, string $role = 'business_admin'): array
    {
        $user = User::query()->create([
            'name' => 'Holiday User',
            'email' => $email,
            'password' => 'StrongPassword123',
            'email_verified_at' => now(),
        ]);
        $tenant = Tenant::query()->create([
            'name' => 'Holiday Workspace',
            'slug' => str_replace(['@', '.'], '-', $email),
        ]);
        $tenant->users()->attach($user->id, ['role' => $role, 'status' => 'active']);

        return [$user, $tenant];
    }
}
