<?php

namespace Tests\Feature;

use App\Jobs\ProcessMetaWebhookEvent;
use App\Models\Tenant;
use App\Models\WebhookEvent;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class StaleWebhookRecoveryCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-23 12:00:00 UTC'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_command_requeues_only_stale_processing_events_and_sets_a_new_lease(): void
    {
        Queue::fake();
        $tenant = $this->tenant('webhook-recovery-stale');
        $stale = $this->event($tenant, 'stale-processing', 'processing', now()->subMinutes(11));
        $fresh = $this->event($tenant, 'fresh-processing', 'processing', now()->subMinutes(4));
        $pending = $this->event($tenant, 'already-pending', 'pending', now()->subMinutes(20));

        $this->artisan('webhooks:reconcile-stale-processing --tenant='.$tenant->id)
            ->expectsOutput('Requeued 1 stale webhook event(s).')
            ->assertExitCode(0);

        Queue::assertPushed(ProcessMetaWebhookEvent::class, 1);
        Queue::assertPushed(ProcessMetaWebhookEvent::class, fn (ProcessMetaWebhookEvent $job): bool => $job->eventId === $stale->id);

        $this->assertDatabaseHas('webhook_events', [
            'id' => $stale->id,
            'status' => 'pending',
            'error_summary' => 'Recovered after a stale processing lease; queued for retry.',
            'updated_at' => now()->toDateTimeString(),
        ]);
        $this->assertDatabaseHas('webhook_events', ['id' => $fresh->id, 'status' => 'processing']);
        $this->assertDatabaseHas('webhook_events', ['id' => $pending->id, 'status' => 'pending']);
    }

    public function test_command_does_not_requeue_a_recently_claimed_event_or_repeat_a_recovery(): void
    {
        Queue::fake();
        $tenant = $this->tenant('webhook-recovery-lease');
        $event = $this->event($tenant, 'leased-event', 'processing', now()->subMinutes(11));

        $this->artisan('webhooks:reconcile-stale-processing --tenant='.$tenant->id)->assertExitCode(0);
        $this->artisan('webhooks:reconcile-stale-processing --tenant='.$tenant->id)
            ->expectsOutput('Requeued 0 stale webhook event(s).')
            ->assertExitCode(0);

        Queue::assertPushed(ProcessMetaWebhookEvent::class, 1);
        $this->assertDatabaseHas('webhook_events', ['id' => $event->id, 'status' => 'pending']);
    }

    public function test_tenant_option_never_recovers_another_tenants_webhook_event(): void
    {
        Queue::fake();
        $tenantA = $this->tenant('webhook-recovery-tenant-a');
        $tenantB = $this->tenant('webhook-recovery-tenant-b');
        $foreignEvent = $this->event($tenantB, 'foreign-stale-event', 'processing', now()->subMinutes(20));

        $this->artisan('webhooks:reconcile-stale-processing --tenant='.$tenantA->id)
            ->expectsOutput('Requeued 0 stale webhook event(s).')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
        $this->assertDatabaseHas('webhook_events', ['id' => $foreignEvent->id, 'status' => 'processing']);
    }

    private function tenant(string $slug): Tenant
    {
        return Tenant::query()->create([
            'name' => 'Webhook Recovery Workspace',
            'slug' => $slug,
            'status' => 'active',
        ]);
    }

    private function event(Tenant $tenant, string $key, string $status, DateTimeInterface $updatedAt): WebhookEvent
    {
        $event = WebhookEvent::query()->create([
            'tenant_id' => $tenant->id,
            'provider' => 'meta_whatsapp',
            'provider_event_key' => hash('sha256', $key),
            'event_type' => 'message.received',
            'payload' => ['from' => '923001112233', 'id' => 'wamid.'.$key],
            'status' => $status,
            'attempts' => 1,
            'received_at' => now()->subMinutes(30),
        ]);

        DB::table('webhook_events')->where('id', $event->id)->update([
            'updated_at' => $updatedAt->toDateTimeString(),
        ]);

        return $event->fresh();
    }
}
