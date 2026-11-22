<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\ConversationNote;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ConversationHandoverTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_take_conversation_add_private_note_reply_and_release_automation(): void
    {
        config(['services.meta.graph_api_version' => 'v24.0']);
        Http::fake([
            'https://graph.facebook.com/v24.0/123456789012345/messages' => Http::response([
                'messages' => [['id' => 'wamid.staff-inbox-reply']],
            ], 200),
        ]);

        [$user, $tenant] = $this->workspace('handover-staff@example.test', 'staff');
        $conversation = $this->conversation($tenant, 'WAITING_FOR_STAFF');

        $this->actingAs($user)->withSession(['current_tenant_id' => $tenant->id]);

        $this->post('/conversations/'.$conversation->id.'/take')->assertRedirect();
        $this->assertDatabaseHas('conversations', [
            'id' => $conversation->id,
            'automation_state' => 'HUMAN_ACTIVE',
            'assigned_user_id' => $user->id,
        ]);

        $this->post('/conversations/'.$conversation->id.'/notes', ['body' => 'Customer prefers afternoon appointments.'])->assertRedirect();
        $note = TenantContext::runForTenant($tenant->id, fn () => ConversationNote::query()->firstOrFail());
        $this->assertSame('Customer prefers afternoon appointments.', $note->body);
        $this->assertStringNotContainsString('Customer prefers afternoon appointments.', (string) $note->getRawOriginal('body'));

        $this->post('/conversations/'.$conversation->id.'/reply', ['body' => 'A team member will help you shortly.'])->assertRedirect();
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'provider_message_id' => 'wamid.staff-inbox-reply',
        ]);

        $this->post('/conversations/'.$conversation->id.'/release')->assertRedirect();
        $this->assertDatabaseHas('conversations', [
            'id' => $conversation->id,
            'automation_state' => 'AUTO_ACTIVE',
            'assigned_user_id' => null,
        ]);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('audit_events', 4);
        $this->assertDatabaseHas('audit_events', [
            'tenant_id' => $tenant->id,
            'actor_user_id' => $user->id,
            'event' => 'conversations.take',
        ]);
        $this->assertDatabaseHas('audit_events', [
            'tenant_id' => $tenant->id,
            'actor_user_id' => $user->id,
            'event' => 'conversations.reply',
        ]);
    }

    public function test_staff_cannot_send_a_reply_without_taking_ownership_first(): void
    {
        Http::fake();
        [$user, $tenant] = $this->workspace('handover-not-owner@example.test', 'staff');
        $conversation = $this->conversation($tenant, 'WAITING_FOR_STAFF');

        $this->actingAs($user)->withSession(['current_tenant_id' => $tenant->id])
            ->post('/conversations/'.$conversation->id.'/reply', ['body' => 'I will help.'])
            ->assertStatus(409);

        Http::assertNothingSent();
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_staff_cannot_open_another_tenants_conversation(): void
    {
        [$user, $tenantA] = $this->workspace('handover-tenant-a@example.test', 'staff');
        [, $tenantB] = $this->workspace('handover-tenant-b@example.test', 'business_admin');
        $conversation = $this->conversation($tenantB, 'WAITING_FOR_STAFF');

        $this->actingAs($user)->withSession(['current_tenant_id' => $tenantA->id])
            ->get('/conversations/'.$conversation->id)
            ->assertNotFound();
    }

    public function test_business_admin_can_assign_conversation_to_another_active_workspace_member(): void
    {
        [$admin, $tenant] = $this->workspace('handover-admin@example.test', 'business_admin');
        $staff = User::query()->create([
            'name' => 'Assigned Staff',
            'email' => 'assigned-staff@example.test',
            'password' => 'StrongPassword123',
        ]);
        $staff->forceFill(['email_verified_at' => now()])->save();
        $tenant->users()->attach($staff->id, ['role' => 'staff', 'status' => 'active']);
        $conversation = $this->conversation($tenant, 'AUTO_ACTIVE');

        $this->actingAs($admin)->withSession(['current_tenant_id' => $tenant->id])
            ->put('/conversations/'.$conversation->id.'/assign', ['assigned_user_id' => $staff->id])
            ->assertRedirect();

        $this->assertDatabaseHas('conversations', [
            'id' => $conversation->id,
            'assigned_user_id' => $staff->id,
            'automation_state' => 'WAITING_FOR_STAFF',
        ]);
    }

    public function test_staff_cannot_assign_or_close_conversations(): void
    {
        [$user, $tenant] = $this->workspace('handover-staff-restrictions@example.test', 'staff');
        $conversation = $this->conversation($tenant, 'WAITING_FOR_STAFF');

        $this->actingAs($user)->withSession(['current_tenant_id' => $tenant->id]);
        $this->put('/conversations/'.$conversation->id.'/assign', ['assigned_user_id' => $user->id])->assertForbidden();
        $this->post('/conversations/'.$conversation->id.'/close')->assertForbidden();
    }

    private function conversation(Tenant $tenant, string $state): Conversation
    {
        return TenantContext::runForTenant($tenant->id, function () use ($state): Conversation {
            WhatsAppConnection::query()->create([
                'phone_number_id' => '123456789012345',
                'waba_id' => '987654321098765',
                'access_token' => 'test-long-lived-access-token',
                'status' => 'active',
                'verified_at' => now(),
            ]);
            $customer = Customer::query()->create(['whatsapp_wa_id' => '923001112233']);
            $conversation = Conversation::query()->create([
                'customer_id' => $customer->id,
                'status' => 'open',
                'automation_state' => $state,
            ]);
            Message::query()->create([
                'conversation_id' => $conversation->id,
                'direction' => 'inbound',
                'message_type' => 'text',
                'body' => 'Please have someone contact me.',
                'provider_message_id' => 'wamid.handover-'.str_replace('-', '', $conversation->id),
                'delivery_status' => 'received',
                'sent_at' => now(),
            ]);

            return $conversation;
        });
    }

    private function workspace(string $email, string $role): array
    {
        $user = User::query()->create([
            'name' => 'Workspace Member',
            'email' => $email,
            'password' => 'StrongPassword123',
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $tenant = Tenant::query()->create([
            'name' => 'Conversation Workspace',
            'slug' => str_replace(['@', '.'], '-', $email),
        ]);
        $tenant->users()->attach($user->id, ['role' => $role, 'status' => 'active']);

        return [$user, $tenant];
    }
}
