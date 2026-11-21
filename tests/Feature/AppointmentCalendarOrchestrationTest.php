<?php

namespace Tests\Feature;

use App\Exceptions\GoogleCalendarBusyException;
use App\Models\Appointment;
use App\Models\BusinessHour;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Staff;
use App\Models\StaffService;
use App\Models\StaffWorkingHour;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Booking\AppointmentBookingService;
use App\Services\Booking\AppointmentCalendarOrchestrator;
use App\Services\Google\GoogleCalendarService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class FakeAppointmentGoogleCalendarService extends GoogleCalendarService
{
    public ?string $existingEventId = null;
    public string $createdEventId = 'google-event-created';
    public ?Throwable $availabilityFailure = null;
    public ?Throwable $createFailure = null;
    public ?Throwable $deleteFailure = null;

    public function findAppointmentEvent(Appointment $appointment): ?string
    {
        return $this->existingEventId;
    }

    public function assertAppointmentCalendarAvailable(Appointment $appointment): void
    {
        if ($this->availabilityFailure) {
            throw $this->availabilityFailure;
        }
    }

    public function createAppointmentEvent(Appointment $appointment): string
    {
        if ($this->createFailure) {
            throw $this->createFailure;
        }

        return $this->createdEventId;
    }

    public function deleteAppointmentEvent(Appointment $appointment): void
    {
        if ($this->deleteFailure) {
            throw $this->deleteFailure;
        }
    }
}

class AppointmentCalendarOrchestrationTest extends TestCase
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

    public function test_appointment_is_confirmed_only_after_freebusy_and_event_creation_succeed(): void
    {
        [$tenant, $appointment] = $this->booking('calendar-confirm');
        $calendar = new FakeAppointmentGoogleCalendarService();
        $calendar->createdEventId = 'google-event-confirmed';
        $this->app->instance(GoogleCalendarService::class, $calendar);

        $confirmed = TenantContext::runForTenant($tenant->id, fn () => app(AppointmentCalendarOrchestrator::class)->confirm($appointment->id));
        $this->assertSame('confirmed', $confirmed->status);
        $this->assertSame('synced', $confirmed->calendar_sync_status);
        $this->assertSame('google-event-confirmed', $confirmed->external_event_id);

        $cancelled = TenantContext::runForTenant($tenant->id, fn () => app(AppointmentCalendarOrchestrator::class)->cancel($appointment->id));
        $this->assertSame('cancelled', $cancelled->status);
    }

    public function test_calendar_busy_response_releases_the_temporary_hold_without_creating_an_event(): void
    {
        [$tenant, $appointment] = $this->booking('calendar-busy');
        $calendar = new FakeAppointmentGoogleCalendarService();
        $calendar->availabilityFailure = new GoogleCalendarBusyException('Calendar is busy.');
        $this->app->instance(GoogleCalendarService::class, $calendar);

        try {
            TenantContext::runForTenant($tenant->id, fn () => app(AppointmentCalendarOrchestrator::class)->confirm($appointment->id));
            $this->fail('Calendar conflict should prevent confirmation.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('appointment', $exception->errors());
        }

        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'status' => 'failed',
            'calendar_sync_status' => 'failed',
        ]);
    }

    public function test_ambiguous_calendar_create_keeps_the_appointment_reserved_for_reconciliation(): void
    {
        [$tenant, $appointment] = $this->booking('calendar-unknown');
        $calendar = new FakeAppointmentGoogleCalendarService();
        $calendar->createFailure = new RuntimeException('Google Calendar API request failed with HTTP 500.');
        $this->app->instance(GoogleCalendarService::class, $calendar);

        try {
            TenantContext::runForTenant($tenant->id, fn () => app(AppointmentCalendarOrchestrator::class)->confirm($appointment->id));
            $this->fail('Ambiguous provider response must not confirm.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('HTTP 500', $exception->getMessage());
        }

        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'status' => 'calendar_pending',
            'calendar_sync_status' => 'unknown',
        ]);
    }

    public function test_google_rate_limit_keeps_calendar_pending_for_retry_instead_of_releasing_slot(): void
    {
        [$tenant, $appointment] = $this->booking('calendar-rate-limit');
        $calendar = new FakeAppointmentGoogleCalendarService();
        $calendar->availabilityFailure = new RuntimeException('Google Calendar API request failed with HTTP 429.');
        $this->app->instance(GoogleCalendarService::class, $calendar);

        try {
            TenantContext::runForTenant($tenant->id, fn () => app(AppointmentCalendarOrchestrator::class)->confirm($appointment->id));
            $this->fail('A rate-limited calendar request must remain pending.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('HTTP 429', $exception->getMessage());
        }

        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'status' => 'calendar_pending',
            'calendar_sync_status' => 'unknown',
        ]);
    }

    public function test_ambiguous_calendar_deletion_keeps_appointment_confirmed_and_reserved(): void
    {
        [$tenant, $appointment] = $this->booking('calendar-delete-unknown');
        $calendar = new FakeAppointmentGoogleCalendarService();
        $calendar->createdEventId = 'google-event-delete-unknown';
        $calendar->deleteFailure = new RuntimeException('Google Calendar API request failed with HTTP 500.');
        $this->app->instance(GoogleCalendarService::class, $calendar);

        TenantContext::runForTenant($tenant->id, fn () => app(AppointmentCalendarOrchestrator::class)->confirm($appointment->id));

        try {
            TenantContext::runForTenant($tenant->id, fn () => app(AppointmentCalendarOrchestrator::class)->cancel($appointment->id));
            $this->fail('An ambiguous calendar deletion must not mark the appointment cancelled.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('HTTP 500', $exception->getMessage());
        }

        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'status' => 'confirmed',
            'calendar_sync_status' => 'unknown',
        ]);
        $this->assertDatabaseHas('appointment_events', [
            'appointment_id' => $appointment->id,
            'event_type' => 'calendar_deletion_unknown',
        ]);
    }

    private function booking(string $slug): array
    {
        $user = User::query()->create([
            'name' => 'Calendar Admin',
            'email' => $slug.'@example.test',
            'password' => 'StrongPassword123',
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $tenant = Tenant::query()->create([
            'name' => 'Calendar Workspace',
            'slug' => $slug,
            'timezone' => 'Asia/Karachi',
        ]);
        $tenant->users()->attach($user->id, ['role' => 'business_admin', 'status' => 'active']);

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
            $staff = Staff::query()->create(['name' => 'Calendar Staff', 'active' => true]);
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
            'calendar-booking-'.$slug,
        ));

        return [$tenant, $appointment];
    }
}
