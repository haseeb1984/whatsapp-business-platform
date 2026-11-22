<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\GoogleCalendarConnection;
use App\Models\Staff;
use App\Models\Tenant;
use App\Services\Google\GoogleCalendarService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleCalendarRescheduleApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_google_calendar_event_is_patched_to_the_new_appointment_time(): void
    {
        [$tenant, $appointment] = $this->calendarAppointment();
        Http::fake([
            'www.googleapis.com/calendar/v3/calendars/primary/events/google-event-reschedule' => Http::response([
                'id' => 'google-event-reschedule',
            ], 200),
        ]);

        $newStart = CarbonImmutable::parse('2026-11-25 06:00:00 UTC');
        $newEnd = $newStart->addMinutes(30);
        $eventId = TenantContext::runForTenant($tenant->id, fn () => app(GoogleCalendarService::class)
            ->updateAppointmentEvent($appointment, $newStart, $newEnd));

        $this->assertSame('google-event-reschedule', $eventId);
        Http::assertSent(fn ($request) => $request->method() === 'PATCH'
            && $request->url() === 'https://www.googleapis.com/calendar/v3/calendars/primary/events/google-event-reschedule'
            && $request['start']['dateTime'] === $newStart->toIso8601String()
            && $request['end']['dateTime'] === $newEnd->toIso8601String()
            && $request['extendedProperties']['private']['platformAppointmentId'] === $appointment->id);
    }

    public function test_ambiguous_google_patch_is_resolved_by_reading_the_existing_event(): void
    {
        [$tenant, $appointment] = $this->calendarAppointment();
        $newStart = CarbonImmutable::parse('2026-11-25 06:00:00 UTC');
        $newEnd = $newStart->addMinutes(30);
        Http::fake([
            'www.googleapis.com/calendar/v3/calendars/primary/events/google-event-reschedule' => Http::sequence()
                ->push(['error' => ['message' => 'temporary upstream failure']], 500)
                ->push([
                    'id' => 'google-event-reschedule',
                    'start' => ['dateTime' => $newStart->toIso8601String()],
                    'end' => ['dateTime' => $newEnd->toIso8601String()],
                    'extendedProperties' => ['private' => [
                        'platformAppointmentId' => $appointment->id,
                        'platformTenantId' => $appointment->tenant_id,
                    ]],
                ], 200),
        ]);

        try {
            $eventId = TenantContext::runForTenant($tenant->id, fn () => app(GoogleCalendarService::class)
                ->updateAppointmentEvent($appointment, $newStart, $newEnd));
        } catch (\RuntimeException $exception) {
            $requests = Http::recorded();
            $this->assertCount(2, $requests, 'The ambiguous update should perform one PATCH and one read-back GET.');
            $this->assertSame('PATCH', $requests[0][0]->method());
            $this->assertSame('GET', $requests[1][0]->method());
            $this->assertSame('google-event-reschedule', $requests[1][1]->json('id'));
            throw $exception;
        }

        $this->assertSame('google-event-reschedule', $eventId);
        Http::assertSent(fn ($request) => $request->method() === 'PATCH');
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && str_ends_with($request->url(), '/events/google-event-reschedule'));
    }

    private function calendarAppointment(): array
    {
        $tenant = Tenant::query()->create([
            'name' => 'Google Reschedule Workspace',
            'slug' => 'google-reschedule-'.uniqid(),
            'timezone' => 'Asia/Karachi',
        ]);

        [$staff, $appointment] = TenantContext::runForTenant($tenant->id, function () use ($tenant): array {
            $staff = Staff::query()->create(['name' => 'Calendar Staff', 'active' => true]);
            GoogleCalendarConnection::query()->create([
                'access_token' => 'google-access-token',
                'refresh_token' => 'google-refresh-token',
                'token_expires_at' => now()->addHour(),
                'status' => 'connected',
                'default_calendar_id' => 'primary',
            ]);

            $appointment = new Appointment();
            $appointment->forceFill([
                'id' => 'appointment-reschedule-api-test',
                'tenant_id' => $tenant->id,
                'staff_id' => $staff->id,
                'service_name' => 'Consultation',
                'external_event_id' => 'google-event-reschedule',
            ]);

            return [$staff, $appointment];
        });

        return [$tenant, $appointment];
    }
}
