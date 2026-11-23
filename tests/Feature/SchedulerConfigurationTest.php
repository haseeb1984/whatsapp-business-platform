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
        }
    }
}
