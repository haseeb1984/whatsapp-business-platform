<?php

namespace Tests\Feature;

use App\Jobs\DeliverWhatsAppMessage;
use App\Models\Tenant;
use App\Services\Meta\MetaWhatsAppGraphApi;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OutboundMessageOutboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_outbox_messages_are_requeued_after_a_lost_queue_dispatch(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Outbox Dispatch', 'slug' => 'outbox-dispatch']);
        $message = $this->outboundMessage($tenant, 'pending', now()->subMinutes(8));

        Queue::fake();

        $this->artisan('messages:dispatch-pending', ['--tenant' => $tenant->id])
            ->expectsOutput('Dispatched 1 pending outbound message(s).')
            ->assertExitCode(0);

        Queue::assertPushed(DeliverWhatsAppMessage::class, fn (DeliverWhatsAppMessage $job): bool => $job->tenantId === $tenant->id
            && $job->messageId === $message->id);

        $this->assertDatabaseHas('messages', [
            'id' => $message->id,
            'delivery_status' => 'pending',
        ]);
        $queuedAt = DB::table('messages')->where('id', $message->id)->value('updated_at');
        $this->assertGreaterThan(now()->subMinute()->timestamp, strtotime((string) $queuedAt));
    }

    public function test_delivery_worker_cannot_process_another_tenants_outbox_message(): void
    {
        $owner = Tenant::query()->create(['name' => 'Outbox Owner', 'slug' => 'outbox-owner']);
        $other = Tenant::query()->create(['name' => 'Outbox Other', 'slug' => 'outbox-other']);
        $message = $this->outboundMessage($owner, 'pending', now());

        Http::fake();

        (new DeliverWhatsAppMessage($other->id, $message->id))->handle(app(MetaWhatsAppGraphApi::class));

        $this->assertDatabaseHas('messages', [
            'id' => $message->id,
            'tenant_id' => $owner->id,
            'delivery_status' => 'pending',
        ]);
        Http::assertNothingSent();
        $this->assertFalse(app()->bound(TenantContext::class), 'An outbox job must not leak tenant context.');
    }

    public function test_stale_sending_messages_become_unknown_and_are_never_resent(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Outbox Recovery', 'slug' => 'outbox-recovery']);
        $message = $this->outboundMessage($tenant, 'sending', now()->subMinutes(20));

        Queue::fake();

        $this->artisan('messages:reconcile-stale-outbound', ['--tenant' => $tenant->id])
            ->expectsOutput('Marked 1 stale outbound message(s) as unknown; none were resent.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('messages', [
            'id' => $message->id,
            'delivery_status' => 'unknown',
        ]);
        Queue::assertNothingPushed();
    }

    private function outboundMessage(Tenant $tenant, string $status, $updatedAt): Message
    {
        return TenantContext::runForTenant($tenant->id, function () use ($status, $updatedAt): Message {
            $customer = Customer::query()->create(['whatsapp_wa_id' => '923001112233']);
            $conversation = Conversation::query()->create([
                'customer_id' => $customer->id,
                'status' => 'open',
                'automation_state' => 'AUTO_ACTIVE',
            ]);

            Message::query()->create([
                'conversation_id' => $conversation->id,
                'direction' => 'inbound',
                'message_type' => 'text',
                'body' => 'Hello',
                'delivery_status' => 'received',
                'sent_at' => now()->subHour(),
            ]);

            $message = Message::query()->create([
                'conversation_id' => $conversation->id,
                'direction' => 'outbound',
                'message_type' => 'text',
                'body' => 'Reply',
                'delivery_status' => $status,
                'metadata' => [],
            ]);

            DB::table('messages')->where('id', $message->id)->update(['updated_at' => $updatedAt]);

            return $message;
        });
    }
}
