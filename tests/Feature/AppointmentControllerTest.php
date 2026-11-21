<?php

namespace Tests\Feature;

use App\Models\BusinessHour;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Staff;
use App\Models\StaffService;
use App\Models\StaffWorkingHour;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppointmentControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-22 06:00:00 UTC'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_staff_can_view_availability_and_create_a_temporary_hold(): void
    {
        [$user, $tenant, $service, $staff, $customer] = $this->workspace('appointment-api-staff@example.test', 'staff');
        $this->actingAs($user)->withSession(['current_tenant_id' => $tenant->id]);

        $this->getJson('/business/appointments/availability?service_id='.$service->id.'&date=2026-11-23')
            ->assertOk()
            ->assertJsonPath('timezone', 'Asia/Karachi')
            ->assertJsonPath('slots.0.local_start', '09:00');

        $this->postJson('/business/appointments/holds', [
            'customer_id' => $customer->id,
            'service_id' => $service->id,
            'staff_id' => $staff->id,
            'starts_at' => '2026-11-23T04:00:00+00:00',
            'idempotency_key' => 'appointment-inbound-message-1',
            'source' => 'whatsapp',
        ])->assertStatus(202)->assertJsonPath('appointment.status', 'pending_hold');

        $this->assertDatabaseHas('appointments', [
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'status' => 'pending_hold',
            'calendar_sync_status' => 'pending',
        ]);
    }

    public function test_cross_tenant_service_id_is_rejected_by_availability_endpoint(): void
    {
        [$user, $tenantA] = $this->workspace('appointment-api-a@example.test', 'staff');
        [, $tenantB, $serviceB] = $this->workspace('appointment-api-b@example.test', 'business_admin');

        $this->actingAs($user)->withSession(['current_tenant_id' => $tenantA->id])
            ->getJson('/business/appointments/availability?service_id='.$serviceB->id.'&date=2026-11-23')
            ->assertUnprocessable();
    }

    public function test_staff_can_cancel_only_temporary_pending_holds(): void
    {
        [$user, $tenant, $service, $staff, $customer] = $this->workspace('appointment-api-cancel@example.test', 'staff');
        $this->actingAs($user)->withSession(['current_tenant_id' => $tenant->id]);

        $response = $this->postJson('/business/appointments/holds', [
            'customer_id' => $customer->id,
            'service_id' => $service->id,
            'staff_id' => $staff->id,
            'starts_at' => '2026-11-23 09:00',
            'idempotency_key' => 'appointment-inbound-message-cancel',
        ])->assertStatus(202);

        $appointmentId = $response->json('appointment.id');
        $this->post('/business/appointments/'.$appointmentId.'/cancel-hold')->assertRedirect();

        $this->assertDatabaseHas('appointments', [
            'id' => $appointmentId,
            'status' => 'cancelled',
            'calendar_sync_status' => 'not_required',
        ]);
    }

    private function workspace(string $email, string $role): array
    {
        $user = User::query()->create([
            'name' => 'Appointment User',
            'email' => $email,
            'password' => 'StrongPassword123',
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $tenant = Tenant::query()->create([
            'name' => 'Appointment Workspace',
            'slug' => str_replace(['@', '.'], '-', $email),
            'timezone' => 'Asia/Karachi',
        ]);
        $tenant->users()->attach($user->id, ['role' => $role, 'status' => 'active']);

        [$service, $staff, $customer] = TenantContext::runForTenant($tenant->id, function (): array {
            $service = Service::query()->create([
                'name' => 'Consultation',
                'price_amount' => '100.00',
                'currency' => 'PKR',
                'duration_minutes' => 30,
                'buffer_before_minutes' => 0,
                'buffer_after_minutes' => 0,
                'active' => true,
            ]);
            $staff = Staff::query()->create(['name' => 'Appointment Staff', 'active' => true]);
            StaffService::query()->create(['staff_id' => $staff->id, 'service_id' => $service->id]);
            BusinessHour::query()->create(['weekday' => 0, 'is_closed' => false, 'opens_at' => '09:00', 'closes_at' => '17:00']);
            StaffWorkingHour::query()->create(['staff_id' => $staff->id, 'weekday' => 0, 'is_closed' => false, 'opens_at' => '09:00', 'closes_at' => '17:00']);
            $customer = Customer::query()->create(['whatsapp_wa_id' => '923001112233']);

            return [$service, $staff, $customer];
        });

        return [$user, $tenant, $service, $staff, $customer];
    }
}
