<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\BookingPolicy;
use App\Models\BusinessHour;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Staff;
use App\Models\StaffService;
use App\Models\StaffWorkingHour;
use App\Models\Tenant;
use App\Services\Booking\AppointmentBookingService;
use App\Services\Booking\AppointmentLifecycleService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AppointmentLifecycleTest extends TestCase
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

    public function test_hold_must_enter_calendar_pending_before_it_can_be_confirmed(): void
    {
        [$tenant, $appointment] = $this->hold('lifecycle-confirm');

        TenantContext::runForTenant($tenant->id, function () use ($appointment): void {
            $lifecycle = app(AppointmentLifecycleService::class);

            try {
                $lifecycle->confirmAfterCalendarSync($appointment->id, 'google-event-1');
                $this->fail('A pending hold cannot be confirmed before calendar synchronization.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('appointment', $exception->errors());
            }

            $pending = $lifecycle->markCalendarPending($appointment->id);
            $this->assertSame('calendar_pending', $pending->status);

            $confirmed = $lifecycle->confirmAfterCalendarSync($appointment->id, 'google-event-1');
            $again = $lifecycle->confirmAfterCalendarSync($appointment->id, 'google-event-1');

            $this->assertSame('confirmed', $confirmed->status);
            $this->assertSame('synced', $confirmed->calendar_sync_status);
            $this->assertSame('google-event-1', $confirmed->external_event_id);
            $this->assertSame($confirmed->id, $again->id);
            $this->assertDatabaseHas('appointment_events', ['appointment_id' => $appointment->id, 'event_type' => 'confirmed']);
        });
    }


    public function test_ambiguous_calendar_result_keeps_the_slot_reserved_for_reconciliation(): void
    {
        [$tenant, $appointment] = $this->hold('lifecycle-calendar-unknown');

        TenantContext::runForTenant($tenant->id, function () use ($appointment): void {
            $lifecycle = app(AppointmentLifecycleService::class);
            $lifecycle->markCalendarPending($appointment->id);
            $unknown = $lifecycle->markCalendarSyncUnknown($appointment->id, 'calendar_timeout');

            $this->assertSame('calendar_pending', $unknown->status);
            $this->assertSame('unknown', $unknown->calendar_sync_status);

            $slots = app(\App\Services\Booking\AppointmentAvailabilityService::class)
                ->availableSlots($appointment->service_id, '2026-11-23', $appointment->staff_id);
            $this->assertNotContains('10:00', array_column($slots, 'local_start'));
            $this->assertDatabaseHas('appointment_events', [
                'appointment_id' => $appointment->id,
                'event_type' => 'calendar_sync_unknown',
            ]);
        });
    }

    public function test_temporary_hold_uses_tenant_configured_duration(): void
    {
        [, $appointment] = $this->hold('lifecycle-hold-policy', 25);

        $this->assertTrue($appointment->hold_expires_at->equalTo(now()->addMinutes(25)));
    }

    public function test_failed_calendar_sync_never_confirms_the_appointment(): void
    {
        [$tenant, $appointment] = $this->hold('lifecycle-failure');

        $failed = TenantContext::runForTenant($tenant->id, fn () => app(AppointmentLifecycleService::class)->failCalendarSync($appointment->id, 'provider_unavailable'));

        $this->assertSame('failed', $failed->status);
        $this->assertSame('failed', $failed->calendar_sync_status);
        $this->assertNull($failed->external_event_id);
        $this->assertDatabaseHas('appointment_events', ['appointment_id' => $appointment->id, 'event_type' => 'calendar_sync_failed']);
    }

    public function test_expired_hold_is_committed_as_expired_before_calendar_pending_returns_an_error(): void
    {
        [$tenant, $appointment] = $this->hold('lifecycle-expired-before-calendar');
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-22 06:11:00 UTC'));

        try {
            TenantContext::runForTenant($tenant->id, fn () => app(AppointmentLifecycleService::class)->markCalendarPending($appointment->id));
            $this->fail('An expired hold must not enter calendar synchronization.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('appointment', $exception->errors());
        }

        $this->assertDatabaseHas('appointments', ['id' => $appointment->id, 'status' => 'expired']);
        $this->assertDatabaseHas('appointment_events', ['appointment_id' => $appointment->id, 'event_type' => 'hold_expired']);
    }

    public function test_expiry_processor_marks_only_due_holds_in_a_bounded_tenant_scope(): void
    {
        [$tenant, $appointment] = $this->hold('lifecycle-expiry-worker');
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-22 06:11:00 UTC'));

        $expired = app(AppointmentLifecycleService::class)->expireHoldsForTenant($tenant->id, 10);

        $this->assertSame(1, $expired);
        $this->assertDatabaseHas('appointments', ['id' => $appointment->id, 'status' => 'expired']);
        $this->assertDatabaseHas('appointment_events', ['appointment_id' => $appointment->id, 'event_type' => 'hold_expired']);
    }


    public function test_artisan_expiry_command_processes_a_specific_tenant(): void
    {
        [$tenant, $appointment] = $this->hold('lifecycle-artisan-expiry');
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-22 06:11:00 UTC'));

        $this->artisan('appointments:expire-holds', ['--tenant' => $tenant->id])
            ->expectsOutput('Expired 1 appointment hold(s).')
            ->assertExitCode(0);

        $this->assertDatabaseHas('appointments', ['id' => $appointment->id, 'status' => 'expired']);
    }

    public function test_confirmed_appointment_is_cancelled_only_after_calendar_deletion_callback(): void
    {
        [$tenant, $appointment] = $this->hold('lifecycle-cancel');
        TenantContext::runForTenant($tenant->id, function () use ($appointment): void {
            $lifecycle = app(AppointmentLifecycleService::class);
            $lifecycle->markCalendarPending($appointment->id);
            $lifecycle->confirmAfterCalendarSync($appointment->id, 'google-event-delete-me');
            $cancelled = $lifecycle->cancelAfterCalendarDeletion($appointment->id);
            $again = $lifecycle->cancelAfterCalendarDeletion($appointment->id);

            $this->assertSame('cancelled', $cancelled->status);
            $this->assertSame($cancelled->id, $again->id);
            $this->assertDatabaseHas('appointment_events', ['appointment_id' => $appointment->id, 'event_type' => 'cancelled_after_calendar_deletion']);
        });
    }

    private function hold(string $slug, ?int $holdMinutes = null): array
    {
        $tenant = Tenant::query()->create([
            'name' => 'Lifecycle Workspace',
            'slug' => $slug,
            'timezone' => 'Asia/Karachi',
        ]);

        [$service, $staff, $customer] = TenantContext::runForTenant($tenant->id, function () use ($holdMinutes): array {
            if ($holdMinutes !== null) {
                BookingPolicy::query()->create([
                    'minimum_notice_minutes' => 0,
                    'booking_horizon_days' => 60,
                    'slot_interval_minutes' => 15,
                    'hold_minutes' => $holdMinutes,
                ]);
            }

            $service = Service::query()->create([
                'name' => 'Consultation',
                'price_amount' => '100.00',
                'currency' => 'PKR',
                'duration_minutes' => 30,
                'buffer_before_minutes' => 0,
                'buffer_after_minutes' => 0,
                'active' => true,
            ]);
            $staff = Staff::query()->create(['name' => 'Team Member', 'active' => true]);
            StaffService::query()->create(['staff_id' => $staff->id, 'service_id' => $service->id]);
            BusinessHour::query()->create(['weekday' => 0, 'is_closed' => false, 'opens_at' => '09:00', 'closes_at' => '17:00']);
            StaffWorkingHour::query()->create(['staff_id' => $staff->id, 'weekday' => 0, 'is_closed' => false, 'opens_at' => '09:00', 'closes_at' => '17:00']);
            $customer = Customer::query()->create(['whatsapp_wa_id' => '923001112233']);

            return [$service, $staff, $customer];
        });

        $appointment = TenantContext::runForTenant($tenant->id, fn () => app(AppointmentBookingService::class)->createHold(
            $customer->id,
            $service->id,
            $staff->id,
            CarbonImmutable::parse('2026-11-23 10:00:00', 'Asia/Karachi'),
            'booking-'.$slug,
        ));

        return [$tenant, $appointment];
    }
}
