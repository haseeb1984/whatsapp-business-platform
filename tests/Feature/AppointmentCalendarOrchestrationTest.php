<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\BusinessHour;
use App\Models\Customer;
use App\Models\GoogleCalendarConnection;
use App\Models\Service;
use App\Models\Staff;
use App\Models\StaffService;
use App\Models\StaffWorkingHour;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Booking\AppointmentBookingService;
use App\Services\Booking\AppointmentCalendarOrchestrator;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AppointmentCalendarOrchestrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-22 06:00:00 UTC'));
        config([
            'services.google.client_id' => 'client-id',
            'services.google.client_secret' => 'client-secret',
            'services.google.redirect_uri' => 'https://platform.example.test/business/calendar/callback',
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_appointment_is_confirmed_only_after_freebusy_and_event_creation_succeed(): void
    {
        [$tenant, $appointment] = $this->booking('calendar-confirm');
        $eventId = substr(hash('sha256', 'appointment:'.$appointment->id), 0, 32);

        Http::fake([
            'www.googleapis.com/calendar/v3/calendars/primary/events/'.$eventId => Http::response([], 404),
            'www.googleapis.com/calendar/v3/freeBusy' => Http::response([
                'calendars' => ['primary' => ['busy' => []]],
            ]),
            'www.googleapis.com/calendar/v3/calendars/primary/events' => Http::response(['id' => $eventId], 200),
        ]);

        $confirmed = TenantContext::runForTenant($tenant->id, fn () => app(AppointmentCalendarOrchestrator::class)->confirm($appointment->id));
        $this->assertSame('confirmed', $confirmed->status);
        $this->assertSame('synced', $confirmed->calendar_sync_status);
        $this->assertSame($eventId, $confirmed->external_event_id);

        Http::fake([
            'www.googleapis.com/calendar/v3/calendars/primary/events/'.$eventId => Http::response([], 204),
        ]);

        $cancelled = TenantContext::runForTenant($tenant->id, fn () => app(AppointmentCalendarOrchestrator::class)->cancel($appointment->id));
        $this->assertSame('cancelled', $cancelled->status);
    }

    public function test_calendar_busy_response_releases_the_temporary_hold_without_creating_an_event(): void
    {
        [$tenant, $appointment] = $this->booking('calendar-busy');
        $eventId = substr(hash('sha256', 'appointment:'.$appointment->id), 0, 32);

        Http::fake([
            'www.googleapis.com/calendar/v3/calendars/primary/events/'.$eventId => Http::response([], 404),
            'www.googleapis.com/calendar/v3/freeBusy' => Http::response([
                'calendars' => ['primary' => ['busy' => [[
                    'start' => '2026-11-23T04:55:00Z',
                    'end' => '2026-11-23T05:45:00Z',
                ]]]],
            ]),
        ]);

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
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/events'));
    }

    public function test_ambiguous_calendar_create_keeps_the_appointment_reserved_for_reconciliation(): void
    {
        [$tenant, $appointment] = $this->booking('calendar-unknown');
        $eventId = substr(hash('sha256', 'appointment:'.$appointment->id), 0, 32);

        Http::fake([
            'www.googleapis.com/calendar/v3/calendars/primary/events/'.$eventId => Http::response([], 404),
            'www.googleapis.com/calendar/v3/freeBusy' => Http::response([
                'calendars' => ['primary' => ['busy' => []]],
            ]),
            'www.googleapis.com/calendar/v3/calendars/primary/events' => Http::response(['error' => 'temporary'], 500),
        ]);

        try {
            TenantContext::runForTenant($tenant->id, fn () => app(AppointmentCalendarOrchestrator::class)->confirm($appointment->id));
            $this->fail('Ambiguous provider response must not confirm.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('HTTP 500', $exception->getMessage());
        }

        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'status' => 'calendar_pending',
            'calendar_sync_status' => 'unknown',
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

        Http::fake([
            'www.googleapis.com/calendar/v3/freeBusy' => Http::response([
                'calendars' => ['primary' => ['busy' => []]],
            ]),
        ]);

        $appointment = TenantContext::runForTenant($tenant->id, fn () => app(AppointmentBookingService::class)->createHold(
            $customer->id,
            $service->id,
            $staff->id,
            CarbonImmutable::parse('2026-11-23 10:00:00', 'Asia/Karachi'),
            'calendar-booking-'.$slug,
        ));

        TenantContext::runForTenant($tenant->id, function (): void {
            GoogleCalendarConnection::query()->create([
                'access_token' => 'calendar-access',
                'refresh_token' => 'calendar-refresh',
                'token_expires_at' => now()->addHour(),
                'status' => 'connected',
                'default_calendar_id' => 'primary',
            ]);
        });

        return [$tenant, $appointment];
    }
}
