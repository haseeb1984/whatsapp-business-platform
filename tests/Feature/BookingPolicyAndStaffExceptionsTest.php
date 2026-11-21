<?php

namespace Tests\Feature;

use App\Models\BookingPolicy;
use App\Models\Staff;
use App\Models\StaffAvailabilityException;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingPolicyAndStaffExceptionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_admin_can_configure_booking_policy_and_staff_date_exceptions(): void
    {
        [$admin, $tenant, $staff] = $this->workspace('policy-admin@example.test', 'business_admin');
        $this->actingAs($admin)->withSession(['current_tenant_id' => $tenant->id]);

        $this->put('/business/appointments/policy', [
            'minimum_notice_minutes' => 90,
            'booking_horizon_days' => 45,
            'slot_interval_minutes' => 30,
            'hold_minutes' => 12,
        ])->assertRedirect();

        $policy = TenantContext::runForTenant($tenant->id, fn () => BookingPolicy::query()->firstOrFail());
        $this->assertSame(90, $policy->minimum_notice_minutes);
        $this->assertSame(45, $policy->booking_horizon_days);
        $this->assertSame(30, $policy->slot_interval_minutes);
        $this->assertSame(12, $policy->hold_minutes);

        $this->post('/business/staff/'.$staff->id.'/exceptions', [
            'date' => '2026-12-01',
            'is_closed' => true,
            'opens_at' => '09:00',
            'closes_at' => '17:00',
            'reason' => 'Annual leave',
        ])->assertRedirect();

        $exception = TenantContext::runForTenant($tenant->id, fn () => StaffAvailabilityException::query()->firstOrFail());
        $this->assertTrue($exception->is_closed);
        $this->assertNull($exception->opens_at);
        $this->assertNull($exception->closes_at);

        $this->put('/business/staff/'.$staff->id.'/exceptions/'.$exception->id, [
            'date' => '2026-12-01',
            'is_closed' => false,
            'opens_at' => '12:00',
            'closes_at' => '16:00',
            'reason' => 'Afternoon only',
        ])->assertRedirect();

        $this->assertDatabaseHas('staff_availability_exceptions', [
            'id' => $exception->id,
            'is_closed' => false,
            'opens_at' => '12:00:00',
            'closes_at' => '16:00:00',
        ]);
    }

    public function test_staff_cannot_change_booking_policy_or_staff_exceptions(): void
    {
        [$staffUser, $tenant, $staff] = $this->workspace('policy-staff@example.test', 'staff');
        $this->actingAs($staffUser)->withSession(['current_tenant_id' => $tenant->id]);

        $this->put('/business/appointments/policy', [
            'minimum_notice_minutes' => 0,
            'booking_horizon_days' => 30,
            'slot_interval_minutes' => 15,
            'hold_minutes' => 10,
        ])->assertForbidden();

        $this->post('/business/staff/'.$staff->id.'/exceptions', [
            'date' => '2026-12-01',
            'is_closed' => true,
            'reason' => 'Leave',
        ])->assertForbidden();
    }

    public function test_invalid_booking_policy_interval_and_duplicate_staff_exception_are_rejected(): void
    {
        [$admin, $tenant, $staff] = $this->workspace('policy-validation@example.test', 'business_admin');
        $this->actingAs($admin)->withSession(['current_tenant_id' => $tenant->id]);

        $this->put('/business/appointments/policy', [
            'minimum_notice_minutes' => 0,
            'booking_horizon_days' => 30,
            'slot_interval_minutes' => 17,
            'hold_minutes' => 10,
        ])->assertSessionHasErrors('slot_interval_minutes');

        $payload = ['date' => '2026-12-01', 'is_closed' => true, 'reason' => 'Leave'];
        $this->post('/business/staff/'.$staff->id.'/exceptions', $payload)->assertRedirect();
        $this->post('/business/staff/'.$staff->id.'/exceptions', $payload)->assertSessionHasErrors('date');
    }

    private function workspace(string $email, string $role): array
    {
        $user = User::query()->create([
            'name' => 'Booking Administrator',
            'email' => $email,
            'password' => 'StrongPassword123',
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $tenant = Tenant::query()->create([
            'name' => 'Booking Policy Workspace',
            'slug' => str_replace(['@', '.'], '-', $email),
        ]);
        $tenant->users()->attach($user->id, ['role' => $role, 'status' => 'active']);
        $staff = TenantContext::runForTenant($tenant->id, fn () => Staff::query()->create([
            'name' => 'Staff Member',
            'active' => true,
        ]));

        return [$user, $tenant, $staff];
    }
}
