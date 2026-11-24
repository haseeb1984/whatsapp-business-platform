<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\Staff;
use App\Models\TenantInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TenantInvitationTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_admin_can_invite_a_staff_member_and_only_a_hash_is_stored(): void
    {
        Notification::fake();
        [$admin, $tenant] = $this->workspace('invite-admin@example.test');

        $this->actingAs($admin)->withSession(['current_tenant_id' => $tenant->id])
            ->post('/business/invitations', ['email' => 'New.Member@example.test', 'role' => 'staff'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $invitation = TenantInvitation::query()->firstOrFail();
        $this->assertSame('new.member@example.test', $invitation->email);
        $this->assertSame('staff', $invitation->role);
        $this->assertSame(64, strlen($invitation->token_hash));
        $this->assertNotSame('New.Member@example.test', $invitation->token_hash);
        Notification::assertSentOnDemand(\App\Notifications\TenantStaffInvitation::class);
    }

    public function test_invitation_acceptance_activates_only_the_invited_role_and_is_single_use(): void
    {
        [$admin, $tenant] = $this->workspace('invite-owner@example.test');
        $member = User::query()->create([
            'name' => 'Invited Member',
            'email' => 'member@example.test',
            'password' => 'StrongPassword123',
        ]);
        $member->forceFill(['email_verified_at' => now()])->save();
        $token = 'known-one-time-invitation-token';

        $invitation = TenantInvitation::query()->create([
            'tenant_id' => $tenant->id,
            'invited_by' => $admin->id,
            'email' => $member->email,
            'role' => 'manager',
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDay(),
        ]);

        $this->actingAs($member)->get('/invitations/'.$token)->assertOk();
        $this->post('/invitations/'.$token.'/accept')
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('current_tenant_id', $tenant->id);

        $this->assertDatabaseHas('tenant_user', [
            'tenant_id' => $tenant->id,
            'user_id' => $member->id,
            'role' => 'manager',
            'status' => 'active',
        ]);
        $this->assertNotNull($invitation->fresh()->accepted_at);
        $this->assertDatabaseCount('staff_members', 0);

        $this->post('/invitations/'.$token.'/accept')->assertGone();
    }

    public function test_accepting_staff_invitation_links_existing_scheduling_profile_to_user_account(): void
    {
        [$admin, $tenant] = $this->workspace('invite-link-owner@example.test');
        $member = User::query()->create([
            'name' => 'Ayesha Khan',
            'email' => 'ayesha.staff@example.test',
            'password' => 'StrongPassword123',
        ]);
        $member->forceFill(['email_verified_at' => now()])->save();

        $profile = TenantContext::runForTenant($tenant->id, fn () => Staff::query()->create([
            'name' => 'Ayesha Khan',
            'email' => $member->email,
            'active' => true,
        ]));
        $token = 'staff-profile-link-token';

        TenantInvitation::query()->create([
            'tenant_id' => $tenant->id,
            'invited_by' => $admin->id,
            'email' => $member->email,
            'role' => 'staff',
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDay(),
        ]);

        $this->actingAs($member)->post('/invitations/'.$token.'/accept')->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('staff_members', [
            'id' => $profile->id,
            'tenant_id' => $tenant->id,
            'user_id' => $member->id,
            'email' => $member->email,
        ]);
        $this->assertDatabaseCount('staff_members', 1);
    }

    public function test_invitation_cannot_be_accepted_by_a_different_email_address(): void
    {
        [$admin, $tenant] = $this->workspace('invite-owner-two@example.test');
        $other = User::query()->create([
            'name' => 'Other User',
            'email' => 'other@example.test',
            'password' => 'StrongPassword123',
        ]);
        $other->forceFill(['email_verified_at' => now()])->save();
        $token = 'email-bound-invitation-token';

        TenantInvitation::query()->create([
            'tenant_id' => $tenant->id,
            'invited_by' => $admin->id,
            'email' => 'intended@example.test',
            'role' => 'staff',
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDay(),
        ]);

        $this->actingAs($other)->get('/invitations/'.$token)->assertForbidden();
        $this->post('/invitations/'.$token.'/accept')->assertForbidden();
        $this->assertDatabaseMissing('tenant_user', ['tenant_id' => $tenant->id, 'user_id' => $other->id]);
    }

    public function test_expired_and_revoked_invitations_cannot_be_accepted(): void
    {
        [$admin, $tenant] = $this->workspace('invite-owner-three@example.test');
        $member = User::query()->create([
            'name' => 'Pending User',
            'email' => 'pending@example.test',
            'password' => 'StrongPassword123',
        ]);
        $member->forceFill(['email_verified_at' => now()])->save();

        foreach ([
            ['token' => 'expired-token', 'expires_at' => now()->subMinute(), 'revoked_at' => null],
            ['token' => 'revoked-token', 'expires_at' => now()->addDay(), 'revoked_at' => now()],
        ] as $data) {
            TenantInvitation::query()->create([
                'tenant_id' => $tenant->id,
                'invited_by' => $admin->id,
                'email' => $member->email,
                'role' => 'staff',
                'token_hash' => hash('sha256', $data['token']),
                'expires_at' => $data['expires_at'],
                'revoked_at' => $data['revoked_at'],
            ]);
            $this->actingAs($member)->post('/invitations/'.$data['token'].'/accept')->assertGone();
        }

        $this->assertDatabaseMissing('tenant_user', ['tenant_id' => $tenant->id, 'user_id' => $member->id]);
    }

    public function test_staff_role_cannot_issue_invitations_and_admin_role_cannot_be_granted_by_invite(): void
    {
        Notification::fake();
        [$staff, $tenant] = $this->workspace('invite-staff@example.test', 'staff');

        $this->actingAs($staff)->withSession(['current_tenant_id' => $tenant->id])
            ->post('/business/invitations', ['email' => 'new@example.test', 'role' => 'staff'])
            ->assertForbidden();

        [$admin, $adminTenant] = $this->workspace('invite-admin-role@example.test');
        $this->actingAs($admin)->withSession(['current_tenant_id' => $adminTenant->id])
            ->post('/business/invitations', ['email' => 'new@example.test', 'role' => 'business_admin'])
            ->assertSessionHasErrors('role');

        $this->assertDatabaseCount('tenant_invitations', 0);
    }

    private function workspace(string $email, string $role = 'business_admin'): array
    {
        $user = User::query()->create([
            'name' => 'Workspace Owner',
            'email' => $email,
            'password' => 'StrongPassword123',
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $tenant = Tenant::query()->create([
            'name' => 'Invitation Workspace',
            'slug' => str_replace(['@', '.'], '-', $email),
        ]);
        $tenant->users()->attach($user->id, ['role' => $role, 'status' => 'active']);

        return [$user, $tenant];
    }
}
