<?php

namespace Tests\Feature;

use App\Jobs\SyncAppointmentCalendarEvent;
use App\Models\Appointment;
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
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CalendarPendingRecoveryCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-23 12:00:00 UTC'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_command_dispatches_only_stale_calendar_pending_appointments_and_sets_a_lease(): void
    {
        Queue::fake();
        [$tenant, $stale] = $this->appointment('calendar-recovery-stale');
        [, $fresh] = $this->appointment('calendar-recovery-fresh', $tenant);
        [, $failed] = $this->appointment('calendar-recovery-failed', $tenant);

        DB::table('appointments')->where('id', $stale->id)->update([
            'status' => 'calendar_pending',
            'calendar_sync_status' => 'unknown',
            'updated_at' => now()->subMinutes(11),
        ]);
        DB::table('appointments')->where('id', $fresh->id)->update([
            'status' => 'calendar_pending',
            'calendar_sync_status' => 'pending',
            'updated_at' => now()->subMinutes(4),
        ]);
        DB::table('appointments')->where('id', $failed->id)->update([
            'status' => 'failed',
            'calendar_sync_status' => 'failed',
            'updated_at' => now()->subMinutes(30),
        ]);

        $this->artisan('appointments:reconcile-calendar-pending --tenant='.$tenant->id)
            ->expectsOutput('Dispatched 1 stale Calendar synchronization job(s).')
            ->assertExitCode(0);

        Queue::assertPushed(SyncAppointmentCalendarEvent::class, 1);
        Queue::assertPushed(SyncAppointmentCalendarEvent::class, function (SyncAppointmentCalendarEvent $job) use ($tenant, $stale): bool {
            return $job->tenantId === $tenant->id && $job->appointmentId === $stale->id;
        });

        $this->assertDatabaseHas('appointments', [
            'id' => $stale->id,
            'status' => 'calendar_pending',
            'calendar_sync_status' => 'unknown',
            'updated_at' => now()->toDateTimeString(),
        ]);
    }

    public function test_command_does_not_dispatch_another_job_until_the_ten_minute_lease_expires(): void
    {
        Queue::fake();
        [$tenant, $appointment] = $this->appointment('calendar-recovery-lease');

        TenantContext::runForTenant($tenant->id, function () use ($appointment): void {
            $appointment->forceFill([
                'status' => 'calendar_pending',
                'calendar_sync_status' => 'pending',
                'updated_at' => now()->subMinutes(11),
            ])->save();
        });

        $this->artisan('appointments:reconcile-calendar-pending --tenant='.$tenant->id)->assertExitCode(0);
        $this->artisan('appointments:reconcile-calendar-pending --tenant='.$tenant->id)
            ->expectsOutput('Dispatched 0 stale Calendar synchronization job(s).')
            ->assertExitCode(0);

        Queue::assertPushed(SyncAppointmentCalendarEvent::class, 1);
    }

    public function test_tenant_option_never_dispatches_another_tenants_calendar_appointment(): void
    {
        Queue::fake();
        [$tenantA] = $this->appointment('calendar-recovery-tenant-a');
        [$tenantB, $appointmentB] = $this->appointment('calendar-recovery-tenant-b');

        TenantContext::runForTenant($tenantB->id, function () use ($appointmentB): void {
            $appointmentB->forceFill([
                'status' => 'calendar_pending',
                'calendar_sync_status' => 'unknown',
                'updated_at' => now()->subMinutes(20),
            ])->save();
        });

        $this->artisan('appointments:reconcile-calendar-pending --tenant='.$tenantA->id)
            ->expectsOutput('Dispatched 0 stale Calendar synchronization job(s).')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    private function appointment(string $slug, ?Tenant $existingTenant = null): array
    {
        if ($existingTenant === null) {
            $user = User::query()->create([
                'name' => 'Recovery Admin',
                'email' => $slug.'@example.test',
                'password' => 'StrongPassword123',
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();

            $tenant = Tenant::query()->create([
                'name' => 'Recovery Workspace',
                'slug' => $slug,
                'timezone' => 'Asia/Karachi',
            ]);
            $tenant->users()->attach($user->id, ['role' => 'business_admin', 'status' => 'active']);
        } else {
            $tenant = $existingTenant;
        }

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
            $staff = Staff::query()->create(['name' => 'Recovery Staff', 'active' => true]);
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
                'opens_at' => '09:00',
                'closes_at' => '17:00',
            ]);
            $customer = Customer::query()->create(['whatsapp_wa_id' => '923001112233']);

            return [$service, $staff, $customer];
        });

        $appointment = TenantContext::runForTenant($tenant->id, fn () => Appointment::query()->create([
            'customer_id' => $customer->id,
            'service_id' => $service->id,
            'staff_id' => $staff->id,
            'starts_at' => CarbonImmutable::parse('2026-11-24 10:00:00', 'Asia/Karachi')->utc(),
            'ends_at' => CarbonImmutable::parse('2026-11-24 10:30:00', 'Asia/Karachi')->utc(),
            'status' => 'held',
            'calendar_sync_status' => 'pending',
            'idempotency_key' => 'calendar-recovery-'.$slug,
            'service_name' => 'Consultation',
            'price_amount' => '100.00',
            'currency' => 'PKR',
            'duration_minutes' => 30,
            'reserved_starts_at' => CarbonImmutable::parse('2026-11-24 10:00:00', 'Asia/Karachi')->utc(),
            'reserved_ends_at' => CarbonImmutable::parse('2026-11-24 10:30:00', 'Asia/Karachi')->utc(),
            'source' => 'dashboard',
            'hold_expires_at' => now()->addMinutes(10),
        ]));

        return [$tenant, $appointment];
    }
}
