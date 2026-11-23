<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SensitiveRouteRateLimitTest extends TestCase
{
    public function test_sensitive_integration_and_calendar_mutations_are_rate_limited(): void
    {
        $this->assertRoutesHaveRateLimits([
            'business.calendar.default.update' => 'throttle:20,1',
            'business.calendar.staff.update' => 'throttle:20,1',
            'business.calendar.disconnect' => 'throttle:5,1',
            'business.whatsapp.store' => 'throttle:10,1',
            'business.whatsapp.update' => 'throttle:10,1',
            'business.whatsapp.destroy' => 'throttle:5,1',
        ]);
    }

    public function test_conversation_and_appointment_state_changes_are_rate_limited(): void
    {
        $this->assertRoutesHaveRateLimits([
            'conversations.take' => 'throttle:30,1',
            'conversations.release' => 'throttle:30,1',
            'conversations.handover' => 'throttle:30,1',
            'conversations.assign' => 'throttle:30,1',
            'conversations.close' => 'throttle:30,1',
            'conversations.reopen' => 'throttle:30,1',
            'business.appointments.cancel' => 'throttle:10,1',
        ]);
    }

    /**
     * @param array<string, string> $limits
     */
    private function assertRoutesHaveRateLimits(array $limits): void
    {
        foreach ($limits as $name => $expectedMiddleware) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, "Expected route [{$name}] to exist.");
            $this->assertContains(
                $expectedMiddleware,
                $route->gatherMiddleware(),
                "Expected route [{$name}] to include [{$expectedMiddleware}].",
            );
        }
    }
}
