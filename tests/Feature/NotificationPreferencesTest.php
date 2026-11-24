<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ConversationAssignedNotification;
use App\Notifications\ConversationHandoverNotification;
use App\Services\Messaging\ConversationNotificationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NotificationPreferencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_can_view_and_update_only_their_workspace_notification_preferences(): void
    {
        [$user, $tenant] = $this->workspace('notification-preferences-a');
        [, $otherTenant] = $this->workspace('notification-preferences-b');
        $otherTenant->users()->attach($user->id, ['role' => 'manager', 'status' => 'active']);

        $this->actingAs($user)->withSession(['current_tenant_id' => $tenant->id])
            ->get('/business/notifications')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Business/Notifications/Index')
                ->where('preferences.email_conversation_assignments', true)
                ->where('preferences.email_handover_requests', true));

        $this->put('/business/notifications', [
            'email_conversation_assignments' => false,
            'email_handover_requests' => true,
        ])->assertRedirect()->assertSessionHas('success');

        $tenantAPreferences = json_decode(
            DB::table('tenant_user')->where('tenant_id', $tenant->id)->where('user_id', $user->id)->value('notification_preferences'),
            true,
            8,
            JSON_THROW_ON_ERROR,
        );
        $tenantBStored = DB::table('tenant_user')->where('tenant_id', $otherTenant->id)->where('user_id', $user->id)->value('notification_preferences');

        $expectedPreferences = [
            'email_conversation_assignments' => false,
            'email_handover_requests' => true,
        ];
        ksort($tenantAPreferences);
        ksort($expectedPreferences);
        $this->assertSame($expectedPreferences, $tenantAPreferences);
        $this->assertNull($tenantBStored);
    }

    public function test_invalid_notification_preferences_are_rejected_without_changing_saved_settings(): void
    {
        [$user, $tenant] = $this->workspace('notification-preferences-invalid');
        $this->actingAs($user)->withSession(['current_tenant_id' => $tenant->id]);

        $this->put('/business/notifications', [
            'email_conversation_assignments' => 'sometimes',
            'email_handover_requests' => true,
        ])->assertSessionHasErrors('email_conversation_assignments');

        $this->assertNull(DB::table('tenant_user')
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->value('notification_preferences'));
    }

    public function test_queued_notification_channels_respect_preferences_and_active_membership(): void
    {
        [$user, $tenant] = $this->workspace('notification-channel-preferences');
        $notification = new ConversationAssignedNotification($tenant->id, $tenant->name, 'conversation-id');

        $this->assertSame(['mail'], $notification->via($user));

        DB::table('tenant_user')->where('tenant_id', $tenant->id)->where('user_id', $user->id)
            ->update(['notification_preferences' => json_encode([
                'email_conversation_assignments' => false,
                'email_handover_requests' => true,
            ], JSON_THROW_ON_ERROR)]);

        $this->assertSame([], $notification->via($user));
        $handover = new ConversationHandoverNotification($tenant->id, $tenant->name, 'conversation-id');
        $this->assertSame(['mail'], $handover->via($user));

        DB::table('tenant_user')->where('tenant_id', $tenant->id)->where('user_id', $user->id)
            ->update(['status' => 'suspended']);
        $this->assertSame([], $handover->via($user));
    }

    public function test_assignment_notification_is_sent_only_to_the_active_assignee(): void
    {
        Notification::fake();
        [$owner, $tenant] = $this->workspace('notification-assignment');
        $assignee = $this->user('assignee-notification-assignment@example.test');
        $observer = $this->user('observer-notification-assignment@example.test');
        $tenant->users()->attach($assignee->id, ['role' => 'staff', 'status' => 'active']);
        $tenant->users()->attach($observer->id, ['role' => 'manager', 'status' => 'active']);

        $conversation = TenantContext::runForTenant($tenant->id, function (): Conversation {
            $customer = Customer::query()->create(['whatsapp_wa_id' => '923001112233']);
            return Conversation::query()->create([
                'customer_id' => $customer->id,
                'status' => 'open',
                'automation_state' => 'WAITING_FOR_STAFF',
            ]);
        });

        TenantContext::runForTenant($tenant->id, fn () => app(ConversationNotificationService::class)->assigned(
            $conversation->id,
            $assignee->id,
        ));

        Notification::assertSentTo($assignee, ConversationAssignedNotification::class);
        Notification::assertNotSentTo($observer, ConversationAssignedNotification::class);
        Notification::assertNotSentTo($owner, ConversationAssignedNotification::class);
    }

    public function test_unassigned_handover_notifies_only_active_workspace_admins_and_managers(): void
    {
        Notification::fake();
        [$owner, $tenant] = $this->workspace('notification-handover');
        $manager = $this->user('manager-notification-handover@example.test');
        $staff = $this->user('staff-notification-handover@example.test');
        $tenant->users()->attach($manager->id, ['role' => 'manager', 'status' => 'active']);
        $tenant->users()->attach($staff->id, ['role' => 'staff', 'status' => 'active']);

        $conversation = TenantContext::runForTenant($tenant->id, function (): Conversation {
            $customer = Customer::query()->create(['whatsapp_wa_id' => '923009998877']);
            return Conversation::query()->create([
                'customer_id' => $customer->id,
                'status' => 'open',
                'automation_state' => 'WAITING_FOR_STAFF',
            ]);
        });

        TenantContext::runForTenant($tenant->id, fn () => app(ConversationNotificationService::class)->handover($conversation->id));

        Notification::assertSentTo($owner, ConversationHandoverNotification::class);
        Notification::assertSentTo($manager, ConversationHandoverNotification::class);
        Notification::assertNotSentTo($staff, ConversationHandoverNotification::class);
    }

    private function user(string $email): User
    {
        $user = User::query()->create([
            'name' => 'Notification Member',
            'email' => $email,
            'password' => 'test-password',
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    private function workspace(string $slug): array
    {
        $tenant = Tenant::query()->create(['name' => 'Notification Workspace', 'slug' => $slug]);
        $user = $this->user($slug.'@example.test');
        $tenant->users()->attach($user->id, ['role' => 'business_admin', 'status' => 'active']);

        return [$user, $tenant];
    }
}
