<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\BusinessHoliday;
use App\Models\BookingPolicy;
use App\Models\BusinessHour;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Staff;
use App\Models\StaffAvailabilityException;
use App\Models\StaffService;
use App\Models\StaffWorkingHour;
use App\Models\Tenant;
use App\Services\Booking\AppointmentAvailabilityService;
use App\Services\Booking\AppointmentBookingService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AppointmentAvailabilityTest extends TestCase
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

    public function test_slots_intersect_business_and_staff_hours_and_include_service_buffers(): void
    {
        [$tenant, $service, $staff] = $this->schedule('availability-basic');

        $slots = TenantContext::runForTenant($tenant->id, fn () => app(AppointmentAvailabilityService::class)->availableSlots(
            $service->id,
            '2026-11-23',
            $staff->id,
        ));

        $this->assertNotEmpty($slots);
        $this->assertSame('10:15', $slots[0]['local_start']);
        $this->assertSame('11:15', $slots[0]['local_end']);
        $this->assertSame('Asia/Karachi', $slots[0]['timezone']);
        $this->assertSame('2026-11-23T05:15:00+00:00', $slots[0]['starts_at']);
    }

    public function test_business_holiday_closure_removes_slots_and_special_hours_override_weekly_hours(): void
    {
        [$tenant, $service, $staff] = $this->schedule('availability-holiday');

        TenantContext::runForTenant($tenant->id, function (): void {
            BusinessHoliday::query()->create([
                'date' => '2026-11-23',
                'name' => 'Closed for holiday',
                'is_closed' => true,
            ]);
        });

        $closed = TenantContext::runForTenant($tenant->id, fn () => app(AppointmentAvailabilityService::class)->availableSlots($service->id, '2026-11-23', $staff->id));
        $this->assertSame([], $closed);

        TenantContext::runForTenant($tenant->id, function (): void {
            BusinessHoliday::query()->firstOrFail()->update([
                'name' => 'Holiday special hours',
                'is_closed' => false,
                'opens_at' => '12:00',
                'closes_at' => '14:00',
            ]);
        });

        $special = TenantContext::runForTenant($tenant->id, fn () => app(AppointmentAvailabilityService::class)->availableSlots($service->id, '2026-11-23', $staff->id));
        $this->assertNotEmpty($special);
        $this->assertSame('12:15', $special[0]['local_start']);
    }

    public function test_active_holds_block_overlapping_slots_but_expired_holds_do_not(): void
    {
        [$tenant, $service, $staff, $customer] = $this->schedule('availability-holds');

        $appointment = TenantContext::runForTenant($tenant->id, fn () => app(AppointmentBookingService::class)->createHold(
            $customer->id,
            $service->id,
            $staff->id,
            CarbonImmutable::parse('2026-11-23 10:15:00', 'Asia/Karachi'),
            'inbound-booking-message-1',
        ));

        $slots = TenantContext::runForTenant($tenant->id, fn () => app(AppointmentAvailabilityService::class)->availableSlots($service->id, '2026-11-23', $staff->id));
        $this->assertNotContains('10:30', array_column($slots, 'local_start'));
        $this->assertNotContains('11:15', array_column($slots, 'local_start'));
        $this->assertContains('11:45', array_column($slots, 'local_start'));

        TenantContext::runForTenant($tenant->id, function () use ($appointment): void {
            $appointment->forceFill(['hold_expires_at' => now()->subMinute()])->save();
        });

        $afterExpiry = TenantContext::runForTenant($tenant->id, fn () => app(AppointmentAvailabilityService::class)->availableSlots($service->id, '2026-11-23', $staff->id));
        $this->assertContains('10:15', array_column($afterExpiry, 'local_start'));
    }

    public function test_booking_hold_is_idempotent_and_never_marked_confirmed_without_calendar_sync(): void
    {
        [$tenant, $service, $staff, $customer] = $this->schedule('availability-idempotent');
        $booking = app(AppointmentBookingService::class);

        $first = TenantContext::runForTenant($tenant->id, fn () => $booking->createHold(
            $customer->id,
            $service->id,
            $staff->id,
            CarbonImmutable::parse('2026-11-23 10:15:00', 'Asia/Karachi'),
            'same-inbound-message',
        ));
        $again = TenantContext::runForTenant($tenant->id, fn () => $booking->createHold(
            $customer->id,
            $service->id,
            $staff->id,
            CarbonImmutable::parse('2026-11-23 10:15:00', 'Asia/Karachi'),
            'same-inbound-message',
        ));

        $this->assertSame($first->id, $again->id);
        $this->assertSame('pending_hold', $first->status);
        $this->assertSame('pending', $first->calendar_sync_status);
        $this->assertDatabaseCount('appointments', 1);
        $this->assertDatabaseHas('appointment_events', ['appointment_id' => $first->id, 'event_type' => 'hold_created']);

        try {
            TenantContext::runForTenant($tenant->id, fn () => $booking->createHold(
                $customer->id,
                $service->id,
                $staff->id,
                CarbonImmutable::parse('2026-11-23 11:45:00', 'Asia/Karachi'),
                'same-inbound-message',
            ));
            $this->fail('An idempotency key cannot be reused for a different slot.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('idempotency_key', $exception->errors());
        }
    }


    public function test_staff_date_exception_can_close_or_override_weekly_hours(): void
    {
        [$tenant, $service, $staff] = $this->schedule('availability-staff-exception');

        TenantContext::runForTenant($tenant->id, fn () => StaffAvailabilityException::query()->create([
            'staff_id' => $staff->id,
            'date' => '2026-11-23',
            'is_closed' => true,
            'reason' => 'Annual leave',
        ]));

        $closed = TenantContext::runForTenant($tenant->id, fn () => app(AppointmentAvailabilityService::class)->availableSlots($service->id, '2026-11-23', $staff->id));
        $this->assertSame([], $closed);

        TenantContext::runForTenant($tenant->id, function () use ($staff): void {
            StaffAvailabilityException::query()->firstOrFail()->update([
                'is_closed' => false,
                'opens_at' => '12:00',
                'closes_at' => '14:00',
                'reason' => 'Special afternoon hours',
            ]);
        });

        $special = TenantContext::runForTenant($tenant->id, fn () => app(AppointmentAvailabilityService::class)->availableSlots($service->id, '2026-11-23', $staff->id));
        $this->assertNotEmpty($special);
        $this->assertSame('12:15', $special[0]['local_start']);
    }

    public function test_minimum_notice_and_booking_horizon_are_applied_from_tenant_policy(): void
    {
        [$tenant, $service, $staff] = $this->schedule('availability-booking-policy');
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-23 04:00:00 UTC'));

        TenantContext::runForTenant($tenant->id, fn () => BookingPolicy::query()->create([
            'minimum_notice_minutes' => 120,
            'booking_horizon_days' => 1,
            'slot_interval_minutes' => 15,
            'hold_minutes' => 20,
        ]));

        $slots = TenantContext::runForTenant($tenant->id, fn () => app(AppointmentAvailabilityService::class)->availableSlots($service->id, '2026-11-23', $staff->id));
        $this->assertNotEmpty($slots);
        $this->assertSame('10:00', $slots[0]['local_start']);

        $outsideHorizon = TenantContext::runForTenant($tenant->id, fn () => app(AppointmentAvailabilityService::class)->availableSlots($service->id, '2026-11-25', $staff->id));
        $this->assertSame([], $outsideHorizon);
    }

    public function test_a_service_from_another_tenant_cannot_be_used_for_availability(): void
    {
        [$tenantA] = $this->schedule('availability-tenant-a');
        [$tenantB, $serviceB] = $this->schedule('availability-tenant-b');

        $this->expectException(ValidationException::class);
        TenantContext::runForTenant($tenantA->id, fn () => app(AppointmentAvailabilityService::class)->availableSlots($serviceB->id, '2026-11-23'));
    }

    private function schedule(string $slug): array
    {
        $tenant = Tenant::query()->create([
            'name' => 'Booking Workspace',
            'slug' => $slug,
            'timezone' => 'Asia/Karachi',
        ]);

        return TenantContext::runForTenant($tenant->id, function () use ($tenant): array {
            $service = Service::query()->create([
                'name' => 'Hair styling',
                'price_amount' => '75.00',
                'currency' => 'PKR',
                'duration_minutes' => 60,
                'buffer_before_minutes' => 15,
                'buffer_after_minutes' => 15,
                'active' => true,
            ]);
            $staff = Staff::query()->create(['name' => 'Ayesha', 'active' => true]);
            StaffService::query()->create(['staff_id' => $staff->id, 'service_id' => $service->id]);
            BusinessHour::query()->create([
                'weekday' => 0,
                'is_closed' => false,
                'opens_at' => '09:00',
                'closes_at' => '17:00',
            ]);
            StaffWorkingHour::query()->create([
                'staff_id' => $staff->id,
                'weekday' => 0,
                'is_closed' => false,
                'opens_at' => '10:00',
                'closes_at' => '16:00',
            ]);
            $customer = Customer::query()->create(['whatsapp_wa_id' => '923001112233']);

            return [$tenant, $service, $staff, $customer];
        });
    }
}
