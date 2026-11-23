<?php

namespace Tests\\Feature;

use App\\Jobs\\ProcessMetaWebhookEvent;
use App\\Models\\Tenant;
use App\\Models\\WebhookEvent;
use Illuminate\\Foundation\\Testing\\RefreshDatabase;
use Illuminate\\Support\\Facades\\Queue;
use Tests\\TestCase;

class FailedWebhookReplayCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_can_replay_one_failed_event_for_an_active_tenant(): void
    {
        Queue::fake();
        $tenant = $this->tenant('failed-webhook-replay');
        $event = $this->event($tenant, 'failed');

        $this->artisan('webhooks:replay-failed '.$event->id)
            ->expectsOutput('Failed webhook event queued for replay.')
            ->assertExitCode(0);

        Queue::assertPushed(ProcessMetaWebhookEvent::class, fn (ProcessMetaWebhookEvent $job): bool => $job->eventId === $event->id);
        $this->assertDatabaseHas('webhook_events', [
            'id' => $event->id,
            'status' => 'pending',
            'attempts' => 0,
            'processed_at' => null,
            'error_summary' => 'Manually replayed by an operator; queued for processing.',
        ]);
    }

    public function test_replay_rejects_non_failed_events_and_unknown_ids(): void
    {
        Queue::fake();
        $tenant = $this->tenant('non-failed-webhook-replay');
        $event = $this->event($tenant, 'pending');

        $this->artisan('webhooks:replay-failed '.$event->id)
            ->expectsOutput('No failed webhook event with an active tenant was found; no replay was queued.')
            ->assertExitCode(1);
        $this->artisan('webhooks:replay-failed 00000000-0000-4000-8000-000000000000')
            ->assertExitCode(1);

        Queue::assertNothingPushed();
        $this->assertDatabaseHas('webhook_events', ['id' => $event->id, 'status' => 'pending']);
    }

    public function test_replay_rejects_events_owned_by_suspended_tenants(): void
    {
        Queue::fake();
        $tenant = $this->tenant('suspended-webhook-replay', 'suspended');
        $event = $this->event($tenant, 'failed');

        $this->artisan('webhooks:replay-failed '.$event->id)->assertExitCode(1);

        Queue::assertNothingPushed();
        $this->assertDatabaseHas('webhook_events', ['id' => $event->id, 'status' => 'failed']);
    }

    private function tenant(string $slug, string $status = 'active'): Tenant
    {
        return Tenant::query()->create([
            'name' => 'Webhook Replay Workspace',
            'slug' => $slug,
            'status' => $status,
        ]);
    }

    private function event(Tenant $tenant, string $status): WebhookEvent
    {
        return WebhookEvent::query()->create([
            'tenant_id' => $tenant->id,
            'provider' => 'meta_whatsapp',
            'provider_event_key' => hash('sha256', $tenant->slug.'-'.$status),
            'event_type' => 'message.received',
            'payload' => ['from' => '923001112233', 'id' => 'wamid.'.$tenant->slug],
            'status' => $status,
            'attempts' => 5,
            'received_at' => now()->subMinutes(30),
        ]);
    }
}
