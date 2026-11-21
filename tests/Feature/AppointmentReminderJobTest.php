<?php

namespace Tests\Feature;

use App\Enums\MessageActor;
use App\Jobs\ProcessAppointmentReminder;
use App\Models\Appointment;
use App\Models\AppointmentReminder;
use App\Models\BusinessHour;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Service;
use App\Models\Staff;
use App\Models\StaffService;
use App\Models\StaffWorkingHour;
use App\Models\Tenant;
use App\Services\Booking\AppointmentBookingService;
use App\Services\Booking\AppointmentLifecycleService;
use App\Services\Messaging\WhatsAppMessageService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AppointmentReminderJobTest extends TestCase
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

    public function test_reminder_outside_customer_service_window_is_held_for_template_support(): void
    {
        [$tenant, $appointment, $reminder, $conversation] = $this->confirmedReminder('reminder-window-closed', 'AUTO_ACTIVE', 25);
        $messenger = Mockery::mock(WhatsAppMessageService::class);
        $messenger->shouldNotReceive('sendText');

        (new ProcessAppointmentReminder($tenant->id, $reminder->id))->handle($messenger);

        $this->assertDatabaseHas('appointment_reminders', [
            'id' => $reminder->id,
            'status' => 'blocked_template_required',
            'last_error' => 'customer_service_window_closed',
        ]);
    }

    public function test_reminder_does_not_override_human_handover(): void
    {
        [$tenant, $appointment, $reminder] = $this->confirmedReminder('reminder-human-handover', 'HUMAN_ACTIVE', 1);
        $messenger = Mockery::mock(WhatsAppMessageService::class);
        $messenger->shouldNotReceive('sendText');

        (new ProcessAppointmentReminder($tenant->id, $reminder->id))->handle($messenger);

        $this->assertDatabaseHas('appointment_reminders', [
            'id' => $reminder->id,
            'status' => 'blocked_handover',
            'last_error' => 'conversation_is_under_human_control',
        ]);
    }

    public function test_reminder_uses_idempotent_whatsapp_sender_and_records_provider_message(): void
    {
        [$tenant, $appointment, $reminder, $conversation] = $this->confirmedReminder('reminder-send-success', 'AUTO_ACTIVE', 1);
        $outbound = new Message();
        $outbound->forceFill([
            'delivery_status' => 'sent',
            'provider_message_id' => 'wamid.reminder.123',
            'sent_at' => now(),
        ]);

        $messenger = Mockery::mock(WhatsAppMessageService::class);
        $messenger->shouldReceive('sendText')
            ->once()
            ->with(
                Mockery::type(Conversation::class),
                Mockery::type('string'),
                MessageActor::Automation,
                null,
                null,
                $reminder->id,
            )
            ->andReturn($outbound);

        (new ProcessAppointmentReminder($tenant->id, $reminder->id))->handle($messenger);

        $this->assertDatabaseHas('appointment_reminders', [
            'id' => $reminder->id,
            'status' => 'sent',
            'provider_message_id' => 'wamid.reminder.123',
        ]);
    }


    public function test_scheduler_dispatches_due_reminders_in_a_tenant_scoped_batch(): void
    {
        [$tenant, $appointment, $reminder] = $this->confirmedReminder('reminder-scheduler', 'AUTO_ACTIVE', 1);
        TenantContext::runForTenant($tenant->id, function () use ($reminder): void {
            $reminder->forceFill(['status' => 'pending', 'queued_at' => null, 'scheduled_at' => now()->subMinute()])->save();
        });

        Queue::fake();

        $this->artisan('appointments:dispatch-reminders', ['--tenant' => $tenant->id])
            ->expectsOutput('Dispatched 1 appointment reminder job(s).')
            ->assertExitCode(0);

        Queue::assertPushed(ProcessAppointmentReminder::class, fn (ProcessAppointmentReminder $job): bool => $job->tenantId === $tenant->id
            && $job->reminderId === $reminder->id);
        $this->assertDatabaseHas('appointment_reminders', ['id' => $reminder->id, 'status' => 'queued']);
    }

    private function confirmedReminder(string $slug, string $automationState, int $inboundAgeHours): array
    {
        $tenant = Tenant::query()->create([
            'name' => 'Reminder Workspace',
            'slug' => $slug,
            'timezone' => 'Asia/Karachi',
        ]);

        [$service, $staff, $customer, $conversation] = TenantContext::runForTenant($tenant->id, function () use ($automationState, $inboundAgeHours): array {
            $service = Service::query()->create([
                'name' => 'Consultation',
                'price_amount' => '100.00',
                'currency' => 'PKR',
                'duration_minutes' => 30,
                'buffer_before_minutes' => 0,
                'buffer_after_minutes' => 0,
                'active' => true,
            ]);
            $staff = Staff::query()->create(['name' => 'Reminder Staff', 'active' => true]);
            StaffService::query()->create(['staff_id' => $staff->id, 'service_id' => $service->id]);
            BusinessHour::query()->create(['weekday' => 1, 'is_closed' => false, 'opens_at' => '09:00', 'closes_at' => '17:00']);
            StaffWorkingHour::query()->create(['staff_id' => $staff->id, 'weekday' => 1, 'is_closed' => false, 'opens_at' => '09:00', 'closes_at' => '17:00']);
            $customer = Customer::query()->create(['whatsapp_wa_id' => '923001112233']);
            $conversation = Conversation::query()->create([
                'customer_id' => $customer->id,
                'status' => 'open',
                'automation_state' => $automationState,
                'last_message_at' => now(),
            ]);
            Message::query()->create([
                'conversation_id' => $conversation->id,
                'direction' => 'inbound',
                'message_type' => 'text',
                'body' => 'Hello',
                'delivery_status' => 'received',
                'sent_at' => now()->subHours($inboundAgeHours),
            ]);

            return [$service, $staff, $customer, $conversation];
        });

        $appointment = TenantContext::runForTenant($tenant->id, fn () => app(AppointmentBookingService::class)->createHold(
            $customer->id,
            $service->id,
            $staff->id,
            CarbonImmutable::parse('2026-11-24 10:00:00', 'Asia/Karachi'),
            'reminder-booking-'.$slug,
        ));

        TenantContext::runForTenant($tenant->id, function () use ($appointment): void {
            $lifecycle = app(AppointmentLifecycleService::class);
            $lifecycle->markCalendarPending($appointment->id);
            $lifecycle->confirmAfterCalendarSync($appointment->id, 'google-event-'.$appointment->id);
        });

        $reminder = TenantContext::runForTenant($tenant->id, function () use ($appointment): AppointmentReminder {
            $reminder = AppointmentReminder::query()->where('appointment_id', $appointment->id)->where('reminder_type', '2_hours')->firstOrFail();
            $reminder->forceFill([
                'scheduled_at' => now()->subMinute(),
                'status' => 'queued',
                'queued_at' => now(),
            ])->save();

            return $reminder;
        });

        return [$tenant, $appointment, $reminder, $conversation];
    }
}
