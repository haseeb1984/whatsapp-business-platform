<?php

namespace Tests\Feature;

use App\Jobs\SyncAppointmentReschedule;
use App\Models\Appointment;
use App\Models\AppointmentEvent;
use App\Models\AppointmentReminder;
use App\Models\BusinessHour;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Staff;
use App\Models\StaffService;
use App\Models\StaffWorkingHour;
use App\Models\Tenant;
use App\Services\Booking\AppointmentBookingService;
use App\Services\Booking\AppointmentLifecycleService;
use App\Services\Booking\AppointmentRescheduleService;
use App\Services\Google\GoogleCalendarService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class AppointmentRescheduleServiceTest extends TestCase
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

    public function test_reschedule_updates_google_event_and_replaces_pending_reminders(): void
    {
        [$tenant, $appointment] = $this->confirmedAppointment('reschedule-success');
        $calendar = Mockery::mock(GoogleCalendarService::class);
        $calendar->shouldReceive('updateAppointmentEvent')
            ->once()
            ->with(
                Mockery::type(Appointment::class),
                Mockery::on(fn ($value) => $value->equalTo(CarbonImmutable::parse('2026-11-25 11:00:00', 'Asia/Karachi')->utc())),
                Mockery::on(fn ($value) => $value->equalTo(CarbonImmutable::parse('2026-11-25 11:30:00', 'Asia/Karachi')->utc())),
            )
            ->andReturn('google-event-reschedule');
        $this->app->instance(GoogleCalendarService::class, $calendar);

        $updated = TenantContext::runForTenant($tenant->id, fn () => app(AppointmentRescheduleService::class)->reschedule(
            $appointment->id,
            CarbonImmutable::parse('2026-11-25 11:00:00', 'Asia/Karachi'),
            'reschedule-success-key',
        ));

        $this->assertSame('confirmed', $updated->status);
        $this->assertSame('synced', $updated->calendar_sync_status);
        $this->assertTrue($updated->starts_at->equalTo(CarbonImmutable::parse('2026-11-25 11:00:00', 'Asia/Karachi')->utc()));
        $this->assertDatabaseHas('appointment_reschedules', [
            'appointment_id' => $appointment->id,
            'status' => 'synced',
            'idempotency_key' => 'reschedule-success-key',
        ]);
        $this->assertDatabaseHas('appointment_events', [
            'appointment_id' => $appointment->id,
            'event_type' => 'rescheduled',
        ]);
        TenantContext::runForTenant($tenant->id, function () use ($appointment): void {
            $this->assertSame(2, AppointmentReminder::query()->where('appointment_id', $appointment->id)->where('status', 'cancelled')->count());
            $this->assertSame(2, AppointmentReminder::query()->where('appointment_id', $appointment->id)->where('status', 'pending')->count());
        });
    }

    public function test_ambiguous_calendar_update_keeps_both_slots_reserved_and_queues_reconciliation(): void
    {
        [$tenant, $appointment] = $this->confirmedAppointment('reschedule-unknown');
        Queue::fake();
        $calendar = Mockery::mock(GoogleCalendarService::class);
        $calendar->shouldReceive('updateAppointmentEvent')
            ->once()
            ->andThrow(new RuntimeException('Google Calendar API request failed with HTTP 500.'));
        $this->app->instance(GoogleCalendarService::class, $calendar);

        try {
            TenantContext::runForTenant($tenant->id, fn () => app(AppointmentRescheduleService::class)->reschedule(
                $appointment->id,
                CarbonImmutable::parse('2026-11-25 11:00:00', 'Asia/Karachi'),
                'reschedule-unknown-key',
            ));
            $this->fail('An ambiguous Calendar update must not be committed locally.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('HTTP 500', $exception->getMessage());
        }

        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'status' => 'confirmed',
            'calendar_sync_status' => 'unknown',
            'starts_at' => $appointment->starts_at->utc()->format('Y-m-d H:i:s'),
        ]);
        $this->assertDatabaseHas('appointment_reschedules', [
            'appointment_id' => $appointment->id,
            'status' => 'unknown',
            'idempotency_key' => 'reschedule-unknown-key',
        ]);
        Queue::assertPushed(SyncAppointmentReschedule::class);
        $this->assertDatabaseHas('appointment_events', [
            'appointment_id' => $appointment->id,
            'event_type' => 'reschedule_calendar_update_unknown',
        ]);

        TenantContext::runForTenant($tenant->id, function () use ($appointment): void {
            $availability = app(\App\Services\Booking\AppointmentAvailabilityService::class);
            $oldSlots = $availability->availableSlots($appointment->service_id, '2026-11-24', $appointment->staff_id, null, false);
            $newSlots = $availability->availableSlots($appointment->service_id, '2026-11-25', $appointment->staff_id, null, false);
            $this->assertNotContains('10:00', array_column($oldSlots, 'local_start'));
            $this->assertNotContains('11:00', array_column($newSlots, 'local_start'));
        });
    }

    public function test_retry_job_reconciles_an_unknown_reschedule_and_commits_the_new_time(): void
    {
        [$tenant, $appointment] = $this->confirmedAppointment('reschedule-reconcile');
        Queue::fake();
        $firstCalendar = Mockery::mock(GoogleCalendarService::class);
        $firstCalendar->shouldReceive('updateAppointmentEvent')
            ->once()
            ->andThrow(new RuntimeException('Google Calendar API request failed with HTTP 500.'));
        $this->app->instance(GoogleCalendarService::class, $firstCalendar);

        try {
            TenantContext::runForTenant($tenant->id, fn () => app(AppointmentRescheduleService::class)->reschedule(
                $appointment->id,
                CarbonImmutable::parse('2026-11-25 11:00:00', 'Asia/Karachi'),
                'reschedule-reconcile-key',
            ));
        } catch (RuntimeException) {
            // The durable request remains unknown and is retried below.
        }

        $request = TenantContext::runForTenant($tenant->id, fn () => \App\Models\AppointmentReschedule::query()->where('idempotency_key', 'reschedule-reconcile-key')->firstOrFail());
        $retryCalendar = Mockery::mock(GoogleCalendarService::class);
        $retryCalendar->shouldReceive('updateAppointmentEvent')->once()->andReturn('google-event-reschedule');
        $this->app->instance(GoogleCalendarService::class, $retryCalendar);

        (new SyncAppointmentReschedule($tenant->id, $request->id))->handle(app(AppointmentRescheduleService::class));

        $updated = TenantContext::runForTenant($tenant->id, fn () => Appointment::query()->findOrFail($appointment->id));
        $this->assertSame('confirmed', $updated->status);
        $this->assertSame('synced', $updated->calendar_sync_status);
        $this->assertTrue($updated->starts_at->equalTo(CarbonImmutable::parse('2026-11-25 11:00:00', 'Asia/Karachi')->utc()));
        $this->assertDatabaseHas('appointment_reschedules', ['id' => $request->id, 'status' => 'synced']);
    }

    public function test_reschedule_sync_job_cannot_process_another_tenants_request(): void
    {
        [$ownerTenant, $appointment] = $this->confirmedAppointment('reschedule-owner');
        Queue::fake();

        $firstCalendar = Mockery::mock(GoogleCalendarService::class);
        $firstCalendar->shouldReceive('updateAppointmentEvent')
            ->once()
            ->andThrow(new RuntimeException('Google Calendar API request failed with HTTP 500.'));
        $this->app->instance(GoogleCalendarService::class, $firstCalendar);

        try {
            TenantContext::runForTenant($ownerTenant->id, fn () => app(AppointmentRescheduleService::class)->reschedule(
                $appointment->id,
                CarbonImmutable::parse('2026-11-25 11:00:00', 'Asia/Karachi'),
                'reschedule-owner-isolation-key',
            ));
        } catch (RuntimeException) {
            // Keep the request in its durable unknown state for the isolation check.
        }

        $request = TenantContext::runForTenant($ownerTenant->id, fn () => \App\Models\AppointmentReschedule::query()
            ->where('idempotency_key', 'reschedule-owner-isolation-key')
            ->firstOrFail());

        $otherTenant = Tenant::query()->create([
            'name' => 'Other Workspace',
            'slug' => 'reschedule-other-tenant',
            'timezone' => 'Asia/Karachi',
        ]);

        $calendar = Mockery::mock(GoogleCalendarService::class);
        $calendar->shouldNotReceive('updateAppointmentEvent');
        $this->app->instance(GoogleCalendarService::class, $calendar);

        (new SyncAppointmentReschedule($otherTenant->id, $request->id))
            ->handle(app(AppointmentRescheduleService::class));

        $this->assertSame('unknown', $request->fresh()->status);
        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'tenant_id' => $ownerTenant->id,
            'status' => 'confirmed',
            'calendar_sync_status' => 'unknown',
        ]);
        $this->assertFalse(app()->bound(TenantContext::class));
    }

    private function confirmedAppointment(string $slug): array
    {
        $tenant = Tenant::query()->create([
            'name' => 'Reschedule Workspace',
            'slug' => $slug,
            'timezone' => 'Asia/Karachi',
        ]);

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
            $staff = Staff::query()->create(['name' => 'Reschedule Staff', 'active' => true]);
            StaffService::query()->create(['staff_id' => $staff->id, 'service_id' => $service->id]);
            foreach ([1, 2] as $weekday) {
                BusinessHour::query()->create(['weekday' => $weekday, 'is_closed' => false, 'opens_at' => '09:00', 'closes_at' => '17:00']);
                StaffWorkingHour::query()->create(['staff_id' => $staff->id, 'weekday' => $weekday, 'is_closed' => false, 'opens_at' => '09:00', 'closes_at' => '17:00']);
            }
            $customer = Customer::query()->create(['whatsapp_wa_id' => '923001112233']);

            return [$service, $staff, $customer];
        });

        $appointment = TenantContext::runForTenant($tenant->id, fn () => app(AppointmentBookingService::class)->createHold(
            $customer->id,
            $service->id,
            $staff->id,
            CarbonImmutable::parse('2026-11-24 10:00:00', 'Asia/Karachi'),
            'reschedule-booking-'.$slug,
        ));

        $appointment = TenantContext::runForTenant($tenant->id, function () use ($appointment): Appointment {
            $lifecycle = app(AppointmentLifecycleService::class);
            $lifecycle->markCalendarPending($appointment->id);
            return $lifecycle->confirmAfterCalendarSync($appointment->id, 'google-event-reschedule');
        });

        return [$tenant, $appointment];
    }
}
