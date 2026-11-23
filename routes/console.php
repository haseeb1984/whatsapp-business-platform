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


Artisan::command('appointments:dispatch-reminders {--tenant=}', function (): int {
    $tenantId = $this->option('tenant');
    $dispatched = 0;

    $dispatchForTenant = function (string $id) use (&$dispatched): void {
        \App\Support\Tenancy\TenantContext::runForTenant($id, function () use ($id, &$dispatched): void {
            $candidates = \App\Models\AppointmentReminder::query()
                ->where('scheduled_at', '<=', now())
                ->where(function ($query): void {
                    $query->where('status', 'pending')
                        ->orWhere(function ($queued): void {
                            $queued->where('status', 'queued')
                                ->where(function ($age): void {
                                    $age->whereNull('queued_at')->orWhere('queued_at', '<=', now()->subMinutes(10));
                                });
                        })
                        ->orWhere(function ($processing): void {
                            $processing->where('status', 'processing')->where('updated_at', '<=', now()->subMinutes(10));
                        });
                })
                ->orderBy('scheduled_at')
                ->limit(100)
                ->get(['id']);

            foreach ($candidates as $candidate) {
                $shouldDispatch = \Illuminate\Support\Facades\DB::transaction(function () use ($candidate): bool {
                    $reminder = \App\Models\AppointmentReminder::query()->lockForUpdate()->find($candidate->id);
                    if (! $reminder || $reminder->scheduled_at->isFuture()) {
                        return false;
                    }

                    $staleQueued = $reminder->status === 'queued'
                        && (! $reminder->queued_at || $reminder->queued_at->lessThanOrEqualTo(now()->subMinutes(10)));
                    $staleProcessing = $reminder->status === 'processing'
                        && $reminder->updated_at?->lessThanOrEqualTo(now()->subMinutes(10));

                    if ($reminder->status === 'pending' || $staleQueued || $staleProcessing) {
                        $reminder->forceFill([
                            'status' => 'queued',
                            'queued_at' => now(),
                        ])->save();

                        return true;
                    }

                    return false;
                });

                if ($shouldDispatch) {
                    \App\Jobs\ProcessAppointmentReminder::dispatch($id, $candidate->id)->afterCommit();
                    $dispatched++;
                }
            }
        });
    };

    if (is_string($tenantId) && $tenantId !== '') {
        $dispatchForTenant($tenantId);
    } else {
        Tenant::query()->where('status', 'active')->orderBy('id')->chunkById(100, function ($tenants) use ($dispatchForTenant): void {
            foreach ($tenants as $tenant) {
                $dispatchForTenant($tenant->id);
            }
        });
    }

    $this->info("Dispatched {$dispatched} appointment reminder job(s).");

    return self::SUCCESS;
})->purpose('Queue due appointment reminders in bounded tenant-scoped batches');

Artisan::command('messages:reconcile-stale-outbound {--tenant=}', function (): int {
    $tenantId = $this->option('tenant');
    $reconciled = 0;

    $reconcileForTenant = function (string $id) use (&$reconciled): void {
        \App\Support\Tenancy\TenantContext::runForTenant($id, function () use (&$reconciled): void {
            $candidates = \App\Models\Message::query()
                ->where('direction', 'outbound')
                ->where('delivery_status', 'sending')
                ->where('updated_at', '<=', now()->subMinutes(10))
                ->orderBy('id')
                ->limit(100)
                ->get(['id']);

            foreach ($candidates as $candidate) {
                \Illuminate\Support\Facades\DB::transaction(function () use ($candidate, &$reconciled): void {
                    $message = \App\Models\Message::query()->lockForUpdate()->find($candidate->id);
                    if (! $message || $message->delivery_status !== 'sending'
                        || $message->updated_at?->greaterThan(now()->subMinutes(10))) {
                        return;
                    }

                    $metadata = $message->metadata ?? [];
                    $message->forceFill([
                        'delivery_status' => 'unknown',
                        'metadata' => array_merge($metadata, [
                            'delivery_error' => 'worker_terminated_after_delivery_started; automatic_resend_blocked',
                        ]),
                    ])->save();

                    if ($message->reminder_id !== null) {
                        \App\Models\AppointmentReminder::query()
                            ->whereKey($message->reminder_id)
                            ->where('status', 'processing')
                            ->update([
                                'status' => 'unknown',
                                'last_error' => 'delivery_worker_terminated; automatic_resend_blocked',
                            ]);
                    }

                    $reconciled++;
                });
            }
        });
    };

    if (is_string($tenantId) && $tenantId !== '') {
        $reconcileForTenant($tenantId);
    } else {
        \App\Models\Tenant::query()->where('status', 'active')->orderBy('id')->chunkById(100, function ($tenants) use ($reconcileForTenant): void {
            foreach ($tenants as $tenant) {
                $reconcileForTenant($tenant->id);
            }
        });
    }

    $this->info("Marked {$reconciled} stale outbound message(s) as unknown; none were resent.");

    return self::SUCCESS;
})->purpose('Reconcile crashed outbound delivery workers without risking duplicate WhatsApp sends');

Schedule::command('appointments:expire-holds')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('appointments:dispatch-reminders')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('messages:reconcile-stale-outbound')
    ->everyMinute()
    ->withoutOverlapping();
