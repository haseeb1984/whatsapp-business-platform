<?php

use App\Models\Tenant;
use App\Services\Booking\AppointmentLifecycleService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;


Artisan::command('platform:preflight {--strict}', function (): int {
    $checks = [
        'PHP 8.3 or newer' => PHP_VERSION_ID >= 80300,
        'PDO PostgreSQL extension loaded' => extension_loaded('pdo_pgsql'),
        'cURL extension loaded' => extension_loaded('curl'),
        'OpenSSL extension loaded' => extension_loaded('openssl'),
        'Production environment selected' => config('app.env') === 'production',
        'APP_DEBUG disabled' => config('app.debug') === false,
        'APP_KEY configured' => is_string(config('app.key')) && trim((string) config('app.key')) !== '',
        'HTTPS application URL' => str_starts_with((string) config('app.url'), 'https://'),
        'PostgreSQL selected' => config('database.default') === 'pgsql',
        'PostgreSQL TLS required' => in_array(config('database.connections.pgsql.sslmode'), ['require', 'verify-ca', 'verify-full'], true),
        'Database queue selected' => config('queue.default') === 'database',
        'Queue dispatch after commit' => config('queue.connections.database.after_commit') === true,
        'Queue retry window exceeds delivery job timeout' => (int) config('queue.connections.database.retry_after', 0) > 30,
        'Failed-job storage configured' => config('queue.failed.driver') === 'database-uuids',
        'Session driver is persistent' => config('session.driver') !== 'array',
        'Cache driver supports scheduler locks' => config('cache.default') !== 'array',
        'Secure session cookies' => config('session.secure') === true,
        'Meta Graph API version configured' => is_string(config('services.meta.graph_api_version'))
            && preg_match('/^v\d+\.\d+$/', (string) config('services.meta.graph_api_version')) === 1,
        'Meta app and webhook credentials configured' => collect([
            config('services.meta.app_id'),
            config('services.meta.app_secret'),
            config('services.meta.webhook_verify_token'),
        ])->every(fn ($value): bool => is_string($value) && trim($value) !== ''),
        'SMTP mail transport configured' => config('mail.default') === 'smtp'
            && is_string(config('mail.mailers.smtp.host'))
            && trim((string) config('mail.mailers.smtp.host')) !== ''
            && filter_var(config('mail.from.address'), FILTER_VALIDATE_EMAIL) !== false,
        'Groq API key configured' => is_string(config('services.groq.api_key')) && trim((string) config('services.groq.api_key')) !== '',
        'Google Calendar OAuth configured' => collect([
            config('services.google.client_id'),
            config('services.google.client_secret'),
            config('services.google.redirect_uri'),
        ])->every(fn ($value): bool => is_string($value) && trim($value) !== ''),
        'Storage and bootstrap cache writable' => is_writable(storage_path()) && is_writable(base_path('bootstrap/cache')),
    ];

    $failed = 0;
    foreach ($checks as $label => $passed) {
        $this->line(($passed ? '<fg=green>PASS</>' : '<fg=yellow>WARN</>').' '.$label);
        if (! $passed) {
            $failed++;
        }
    }

    $this->newLine();
    $this->info(count($checks).' checks completed; '.$failed.' warning(s).');

    if ($failed > 0 && $this->option('strict')) {
        $this->error('Production preflight failed. Resolve every warning before deployment.');

        return self::FAILURE;
    }

    return self::SUCCESS;
})->purpose('Check production runtime configuration before deployment without exposing secrets');

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


Artisan::command('messages:dispatch-pending {--tenant=}', function (): int {
    $tenantId = $this->option('tenant');
    $dispatched = 0;

    $dispatchForTenant = function (string $id) use (&$dispatched): void {
        \App\Support\Tenancy\TenantContext::runForTenant($id, function () use ($id, &$dispatched): void {
            $candidates = \App\Models\Message::query()
                ->where('direction', 'outbound')
                ->where('delivery_status', 'pending')
                ->where('updated_at', '<=', now()->subMinutes(5))
                ->orderBy('id')
                ->limit(100)
                ->get(['id']);

            foreach ($candidates as $candidate) {
                $shouldDispatch = \Illuminate\Support\Facades\DB::transaction(function () use ($candidate): bool {
                    $message = \App\Models\Message::query()->lockForUpdate()->find($candidate->id);
                    if (! $message || $message->direction !== 'outbound'
                        || $message->delivery_status !== 'pending'
                        || $message->updated_at?->greaterThan(now()->subMinutes(5))) {
                        return false;
                    }

                    // Refresh updated_at as a bounded dispatch lease. Duplicate queue
                    // deliveries remain safe because the worker atomically claims
                    // pending -> sending before it contacts Meta.
                    $message->touch();

                    return true;
                });

                if ($shouldDispatch) {
                    \App\Jobs\DeliverWhatsAppMessage::dispatch($id, $candidate->id)->afterCommit();
                    $dispatched++;
                }
            }
        });
    };

    if (is_string($tenantId) && $tenantId !== '') {
        $dispatchForTenant($tenantId);
    } else {
        \App\Models\Tenant::query()->where('status', 'active')->orderBy('id')->chunkById(100, function ($tenants) use ($dispatchForTenant): void {
            foreach ($tenants as $tenant) {
                $dispatchForTenant($tenant->id);
            }
        });
    }

    $this->info("Dispatched {$dispatched} pending outbound message(s).");

    return self::SUCCESS;
})->purpose('Recover durable outbound messages whose queue dispatch may have been interrupted');

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

Artisan::command('appointments:reconcile-calendar-pending {--tenant=}', function (): int {
    $tenantId = $this->option('tenant');
    $dispatched = 0;

    $dispatchForTenant = function (string $id) use (&$dispatched): void {
        \App\Support\Tenancy\TenantContext::runForTenant($id, function () use ($id, &$dispatched): void {
            $candidates = \App\Models\Appointment::query()
                ->where('status', 'calendar_pending')
                ->whereIn('calendar_sync_status', ['pending', 'unknown'])
                ->where('updated_at', '<=', now()->subMinutes(10))
                ->orderBy('updated_at')
                ->limit(100)
                ->get(['id']);

            foreach ($candidates as $candidate) {
                $shouldDispatch = \Illuminate\Support\Facades\DB::transaction(function () use ($candidate): bool {
                    $appointment = \App\Models\Appointment::query()->lockForUpdate()->find($candidate->id);
                    if (! $appointment
                        || $appointment->status !== 'calendar_pending'
                        || ! in_array($appointment->calendar_sync_status, ['pending', 'unknown'], true)
                        || $appointment->updated_at?->greaterThan(now()->subMinutes(10))) {
                        return false;
                    }

                    // A ten-minute lease prevents every scheduler tick from flooding
                    // the queue. The worker reconciles by deterministic Google event ID.
                    $appointment->touch();

                    return true;
                });

                if ($shouldDispatch) {
                    \App\Jobs\SyncAppointmentCalendarEvent::dispatch($id, $candidate->id)->afterCommit();
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

    $this->info("Dispatched {$dispatched} stale Calendar synchronization job(s).");

    return self::SUCCESS;
})->purpose('Recover stale calendar-pending appointments through deterministic provider reconciliation');

Schedule::command('appointments:reconcile-calendar-pending')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('appointments:expire-holds')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('appointments:dispatch-reminders')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('messages:reconcile-stale-outbound')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('messages:dispatch-pending')
    ->everyMinute()
    ->withoutOverlapping();

Artisan::command('webhooks:reconcile-stale-processing {--tenant=}', function (): int {
    $tenantId = $this->option('tenant');
    $dispatched = 0;

    $query = \Illuminate\Support\Facades\DB::table('webhook_events')
        ->join('tenants', 'tenants.id', '=', 'webhook_events.tenant_id')
        ->where('tenants.status', 'active')
        ->where('webhook_events.status', 'processing')
        ->where('webhook_events.updated_at', '<=', now()->subMinutes(10))
        ->orderBy('webhook_events.updated_at')
        ->limit(100);

    if (is_string($tenantId) && $tenantId !== '') {
        $query->where('webhook_events.tenant_id', $tenantId);
    }

    $candidates = $query->get(['webhook_events.id']);

    foreach ($candidates as $candidate) {
        $eventId = (string) $candidate->id;
        $shouldDispatch = \Illuminate\Support\Facades\DB::transaction(function () use ($eventId): bool {
            $event = \Illuminate\Support\Facades\DB::table('webhook_events')
                ->where('id', $eventId)
                ->lockForUpdate()
                ->first();

            if (! $event
                || $event->status !== 'processing'
                || \Carbon\CarbonImmutable::parse($event->updated_at)->greaterThan(now()->subMinutes(10))
                || ! $event->tenant_id
                || ! Tenant::query()->whereKey($event->tenant_id)->where('status', 'active')->exists()) {
                return false;
            }

            // Current webhook processing commits the processing and processed
            // states in one database transaction, so a worker crash rolls both
            // back. This lease is retained only to recover legacy/orphaned rows
            // left by earlier versions or manual operational intervention.
            \Illuminate\Support\Facades\DB::table('webhook_events')
                ->where('id', $eventId)
                ->update([
                    'status' => 'pending',
                    'error_summary' => 'Recovered after a stale processing lease; queued for retry.',
                    'updated_at' => now(),
                ]);

            return true;
        });

        if ($shouldDispatch) {
            \App\Jobs\ProcessMetaWebhookEvent::dispatch($eventId)->afterCommit();
            $dispatched++;
        }
    }

    $this->info("Requeued {$dispatched} stale webhook event(s).");

    return self::SUCCESS;
})->purpose('Recover Meta webhook events stranded by terminated workers without duplicating active processing');

Schedule::command('webhooks:reconcile-stale-processing')
    ->everyMinute()
    ->withoutOverlapping();
