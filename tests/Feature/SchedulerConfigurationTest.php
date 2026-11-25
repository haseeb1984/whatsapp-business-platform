<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class SchedulerConfigurationTest extends TestCase
{
    public function test_operational_housekeeping_commands_are_scheduled_every_minute(): void
    {
        $events = collect(app(Schedule::class)->events());

        foreach ([
            'webhooks:reconcile-stale-processing',
            'appointments:reconcile-calendar-pending',
            'appointments:expire-holds',
            'appointments:dispatch-reminders',
            'messages:reconcile-stale-outbound',
            'messages:dispatch-pending',
        ] as $command) {
            $event = $events->first(
                fn ($scheduled): bool => str_contains((string) $scheduled->command, $command)
            );

            $this->assertNotNull($event, "Expected {$command} to be registered with Laravel Scheduler.");
            $this->assertSame('* * * * *', $event->expression, "{$command} must run every minute.");
            $this->assertTrue($event->withoutOverlapping, "{$command} must prevent overlapping scheduler invocations.");
        }
    }
    public function test_ai_usage_retention_cleanup_runs_daily_without_overlap(): void
    {
        $event = collect(app(Schedule::class)->events())->first(
            fn ($scheduled): bool => str_contains((string) $scheduled->command, 'ai-usage:prune')
        );

        $this->assertNotNull($event);
        $this->assertSame('15 2 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }
}
