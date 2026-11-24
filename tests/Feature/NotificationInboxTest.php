<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ConversationAssignedNotification;
use App\Notifications\ConversationHandoverNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NotificationInboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_in_app_notifications_are_persisted_and_filtered_to_the_active_workspace(): void
    {
        $user = $this->user('inbox-member@example.test');
        $tenantA = $this->tenant('inbox-workspace-a');
        $tenantB = $this->tenant('inbox-workspace-b');
        $tenantA->users()->attach($user->id, [
            'role' => 'business_admin',
            'status' => 'active',
            'notification_preferences' => json_encode([
                'email_conversation_assignments' => false,
                'email_handover_requests' => false,
            ], JSON_THROW_ON_ERROR),
        ]);
        $tenantB->users()->attach($user->id, [
            'role' => 'manager',
            'status' => 'active',
            'notification_preferences' => json_encode([
                'email_conversation_assignments' => false,
                'email_handover_requests' => false,
            ], JSON_THROW_ON_ERROR),
        ]);

        Notification::sendNow($user, new ConversationAssignedNotification($tenantA->id, $tenantA->name, 'conversation-a'));
        Notification::sendNow($user, new ConversationHandoverNotification($tenantB->id, $tenantB->name, 'conversation-b'));

        $tenantANotification = $user->notifications()->get()->first(
            fn ($notification) => ($notification->data['tenant_id'] ?? null) === $tenantA->id,
        );
        $tenantBNotification = $user->notifications()->get()->first(
            fn ($notification) => ($notification->data['tenant_id'] ?? null) === $tenantB->id,
        );

        $this->assertNotNull($tenantANotification);
        $this->assertNotNull($tenantBNotification);

        $this->actingAs($user)->withSession(['current_tenant_id' => $tenantA->id])
            ->get('/business/notifications/inbox')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Business/Notifications/Inbox')
                ->where('unread_count', 1)
                ->has('notifications', 1)
                ->where('notifications.0.title', 'Conversation assigned'));

        $this->post('/business/notifications/'.$tenantBNotification->id.'/read')
            ->assertNotFound();

        $this->post('/business/notifications/'.$tenantANotification->id.'/read')
            ->assertRedirect();

        $this->assertNotNull($user->notifications()->findOrFail($tenantANotification->id)->read_at);
        $this->assertNull($user->notifications()->findOrFail($tenantBNotification->id)->read_at);

        $this->post('/business/notifications/read-all')->assertRedirect();
        $this->assertNotNull($user->notifications()->findOrFail($tenantANotification->id)->read_at);
        $this->assertNull($user->notifications()->findOrFail($tenantBNotification->id)->read_at);
    }

    public function test_in_app_notifications_are_stored_even_when_email_delivery_is_disabled(): void
    {
        $user = $this->user('inbox-no-email@example.test');
        $tenant = $this->tenant('inbox-no-email');
        $tenant->users()->attach($user->id, [
            'role' => 'staff',
            'status' => 'active',
            'notification_preferences' => json_encode([
                'email_conversation_assignments' => false,
                'email_handover_requests' => false,
            ], JSON_THROW_ON_ERROR),
        ]);

        Notification::sendNow($user, new ConversationAssignedNotification($tenant->id, $tenant->name, 'conversation-no-email'));

        $this->assertSame(1, $user->notifications()->count());
        $this->assertSame('conversation-no-email', $user->notifications()->firstOrFail()->data['conversation_id']);
    }

    private function user(string $email): User
    {
        $user = User::query()->create([
            'name' => 'Inbox Member',
            'email' => $email,
            'password' => 'test-password',
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    private function tenant(string $slug): Tenant
    {
        return Tenant::query()->create(['name' => 'Inbox Workspace', 'slug' => $slug]);
    }
}
