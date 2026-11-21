<?php

use App\Models\Tenant;
use App\Services\Booking\AppointmentLifecycleService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('platform:about', function (): void {
    $this->info(config('app.name').' — WhatsApp Business Platform');
    $this->line('Environment: '.app()->environment());
    $this->line('Queue: '.config('queue.default'));
})->purpose('Display platform runtime configuration');

Artisan::command('appointments:expire-holds {--tenant=}', function (): int {
    $lifecycle = app(AppointmentLifecycleService::class);
    $tenantId = $this->option('tenant');
    $expired = 0;

    if (is_string($tenantId) && $tenantId !== '') {
        $expired = $lifecycle->expireHoldsForTenant($tenantId, 100);
    } else {
        Tenant::query()
            ->where('status', 'active')
            ->orderBy('id')
            ->chunkById(100, function ($tenants) use ($lifecycle, &$expired): void {
                foreach ($tenants as $tenant) {
                    $expired += $lifecycle->expireHoldsForTenant($tenant->id, 100);
                }
            });
    }

    $this->info("Expired {$expired} appointment hold(s).");

    return self::SUCCESS;
})->purpose('Expire overdue appointment holds in bounded tenant-scoped batches');

Schedule::command('appointments:expire-holds')
    ->everyMinute()
    ->withoutOverlapping();
