<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\PlatformOperationalAlertNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlatformOperationalAlertEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_platform_alerts_are_emailed_once_per_recipient_during_the_cooldown(): void
    {
        config([
            'platform.admin_emails' => ['platform-admin@example.test'],
            'services.groq.alerts.emails' => ['ops@example.test'],
            'services.groq.alerts.email_cooldown_minutes' => 30,
            'services.groq.alerts.failed_jobs_24h' => 5,
        ]);
        Cache::flush();
        Notification::fake();

        $admin = User::factory()->create([
            'email' => 'platform-admin@example.test',
            'email_verified_at' => now(),
        ]);

        for ($i = 0; $i < 5; $i++) {
            DB::table('failed_jobs')->insert([
                'uuid' => (string) Str::uuid(),
                'connection' => 'database',
                'queue' => 'default',
                'payload' => '{}',
                'exception' => 'Test failure',
                'failed_at' => now()->subMinutes($i),
            ]);
        }

        $this->actingAs($admin)->get('/platform')->assertOk();
        $this->actingAs($admin)->get('/platform')->assertOk();

        Notification::assertSentOnDemand(
            PlatformOperationalAlertNotification::class,
            fn ($notification, $channels, $notifiable): bool =>
                $notifiable->routes['mail'] === 'ops@example.test'
                && $notification->code === 'failed_jobs_high'
        );
        Notification::assertSentOnDemandTimes(PlatformOperationalAlertNotification::class, 1);
    }

    public function test_platform_alert_emails_are_not_dispatched_when_no_recipients_are_configured(): void
    {
        config([
            'platform.admin_emails' => ['platform-admin@example.test'],
            'services.groq.alerts.emails' => [],
            'services.groq.alerts.failed_jobs_24h' => 1,
        ]);
        Notification::fake();

        $admin = User::factory()->create([
            'email' => 'platform-admin@example.test',
            'email_verified_at' => now(),
        ]);

        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'Test failure',
            'failed_at' => now(),
        ]);

        $this->actingAs($admin)->get('/platform')->assertOk();

        Notification::assertNothingSent();
    }
}
