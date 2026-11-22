<?php

namespace App\Jobs;

use App\Models\AppointmentReschedule;
use App\Services\Booking\AppointmentRescheduleService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncAppointmentReschedule implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120, 300];

    public int $uniqueFor = 900;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $rescheduleId,
    ) {
    }

    public function uniqueId(): string
    {
        return $this->tenantId.':'.$this->rescheduleId;
    }

    public function handle(AppointmentRescheduleService $reschedules): void
    {
        TenantContext::runForTenant($this->tenantId, function () use ($reschedules): void {
            $request = AppointmentReschedule::query()->find($this->rescheduleId);
            if (! $request || ! in_array($request->status, ['pending', 'unknown'], true)) {
                return;
            }

            $reschedules->reschedule(
                $request->appointment_id,
                CarbonImmutable::instance($request->new_starts_at),
                $request->idempotency_key,
            );
        });
    }

    public function failed(Throwable $exception): void
    {
        TenantContext::runForTenant($this->tenantId, function () use ($exception): void {
            Log::error('Appointment reschedule reconciliation exhausted retries.', [
                'tenant_id' => $this->tenantId,
                'reschedule_id' => $this->rescheduleId,
                'exception' => $exception::class,
            ]);
        });
    }
}
