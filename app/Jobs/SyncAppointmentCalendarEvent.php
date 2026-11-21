<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Services\Booking\AppointmentCalendarOrchestrator;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class SyncAppointmentCalendarEvent implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120, 300];

    public function __construct(
        public readonly string $tenantId,
        public readonly string $appointmentId,
    ) {
    }

    public function handle(AppointmentCalendarOrchestrator $calendar): void
    {
        TenantContext::runForTenant($this->tenantId, function () use ($calendar): void {
            try {
                $calendar->confirm($this->appointmentId);
            } catch (ValidationException $exception) {
                Log::warning('Appointment Calendar synchronization was rejected.', [
                    'tenant_id' => $this->tenantId,
                    'appointment_id' => $this->appointmentId,
                    'error_keys' => array_keys($exception->errors()),
                ]);
            } catch (RuntimeException $exception) {
                // Ambiguous provider failures are retried with the same deterministic Google event ID.
                throw $exception;
            }
        });
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Appointment Calendar synchronization exhausted retries.', [
            'tenant_id' => $this->tenantId,
            'appointment_id' => $this->appointmentId,
            'exception' => $exception::class,
        ]);
    }
}
