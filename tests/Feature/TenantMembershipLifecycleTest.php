<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Staff;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantMembershipLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_admin_can_change_staff_and_manager_roles_but_not_grant_admin_access(): void
    {
        [$admin, $tenant] = $this->workspace('membership-admin@example.test');
        $member = $this->member($tenant, 'member-role@example.test', 'staff');

        $this->actingAs($admin)->withSession(['current_tenant_id' => $tenant->id])
            ->patch('/business/members/'.$member->id.'/role', ['role' => 'manager'])
            ->assertRedirect();

        $this->assertDatabaseHas('tenant_user', [
            'tenant_id' => $tenant->id, 'user_id' => $member->id, 'role' => 'manager', 'status' => 'active',
        ]);

        $this->patch('/business/members/'.$member->id.'/role', ['role' => 'business_admin'])
            ->assertSessionHasErrors('role');
        $this->assertDatabaseMissing('tenant_user', [
            'tenant_id' => $tenant->id, 'user_id' => $member->id, 'role' => 'business_admin',
        ]);
    }

    public function test_suspend_and_reactivate_updates_membership_and_linked_staff_profile(): void
    {
        [$admin, $tenant] = $this->workspace('membership-suspend-admin@example.test');
        $member = $this->member($tenant, 'member-suspend@example.test', 'staff');
        $profile = TenantContext::runForTenant($tenant->id, fn () => Staff::query()->create([
            'name' => 'Linked Staff', 'email' => $member->email, 'user_id' => $member->id, 'active' => true,
        ]));
        $conversation = $this->conversation($tenant, $member->id);

        $this->actingAs($admin)->withSession(['current_tenant_id' => $tenant->id])
            ->post('/business/members/'.$member->id.'/suspend')->assertRedirect();

        $this->assertDatabaseHas('tenant_user', ['tenant_id' => $tenant->id, 'user_id' => $member->id, 'status' => 'suspended']);
        $this->assertDatabaseHas('staff_members', ['id' => $profile->id, 'active' => false, 'user_id' => $member->id]);
        $this->assertDatabaseHas('conversations', ['id' => $conversation->id, 'assigned_user_id' => null, 'automation_state' => 'WAITING_FOR_STAFF']);

        $this->post('/business/members/'.$member->id.'/reactivate')->assertRedirect();

        $this->assertDatabaseHas('tenant_user', ['tenant_id' => $tenant->id, 'user_id' => $member->id, 'status' => 'active']);
        $this->assertDatabaseHas('staff_members', ['id' => $profile->id, 'active' => true, 'user_id' => $member->id]);
    }

    public function test_removal_detaches_membership_and_unlinks_deactivated_staff_profile(): void
    {
        [$admin, $tenant] = $this->workspace('membership-remove-admin@example.test');
        $member = $this->member($tenant, 'member-remove@example.test', 'staff');
        $profile = TenantContext::runForTenant($tenant->id, fn () => Staff::query()->create([
            'name' => 'Linked Staff', 'email' => $member->email, 'user_id' => $member->id, 'active' => true,
        ]));
        $conversation = $this->conversation($tenant, $member->id);

        $this->actingAs($admin)->withSession(['current_tenant_id' => $tenant->id])
            ->delete('/business/members/'.$member->id)->assertRedirect();

        $this->assertDatabaseMissing('tenant_user', ['tenant_id' => $tenant->id, 'user_id' => $member->id]);
        $this->assertDatabaseHas('staff_members', ['id' => $profile->id, 'user_id' => null, 'active' => false]);
        $this->assertDatabaseHas('conversations', ['id' => $conversation->id, 'assigned_user_id' => null, 'automation_state' => 'WAITING_FOR_STAFF']);
        $this->assertDatabaseHas('users', ['id' => $member->id, 'email' => $member->email]);
    }

    public function test_admin_cannot_modify_self_or_another_business_admin_through_staff_controls(): void
    {
        [$admin, $tenant] = $this->workspace('membership-self-admin@example.test');
        $otherAdmin = $this->member($tenant, 'membership-other-admin@example.test', 'business_admin');
        $this->actingAs($admin)->withSession(['current_tenant_id' => $tenant->id]);

        $this->patch('/business/members/'.$admin->id.'/role', ['role' => 'manager'])->assertStatus(409);
        $this->post('/business/members/'.$admin->id.'/suspend')->assertStatus(409);
        $this->delete('/business/members/'.$admin->id)->assertStatus(409);
        $this->post('/business/members/'.$otherAdmin->id.'/suspend')->assertForbidden();
        $this->delete('/business/members/'.$otherAdmin->id)->assertForbidden();

        $this->assertDatabaseHas('tenant_user', ['tenant_id' => $tenant->id, 'user_id' => $admin->id, 'status' => 'active']);
        $this->assertDatabaseHas('tenant_user', ['tenant_id' => $tenant->id, 'user_id' => $otherAdmin->id, 'status' => 'active']);
    }

    public function test_only_business_admin_can_manage_workspace_memberships(): void
    {
        [$admin, $tenant] = $this->workspace('membership-manager-admin@example.test');
        $manager = $this->member($tenant, 'membership-manager@example.test', 'manager');
        $staff = $this->member($tenant, 'membership-staff@example.test', 'staff');

        $this->actingAs($manager)->withSession(['current_tenant_id' => $tenant->id]);
        $this->patch('/business/members/'.$staff->id.'/role', ['role' => 'manager'])->assertForbidden();
        $this->post('/business/members/'.$staff->id.'/suspend')->assertForbidden();
        $this->post('/business/members/'.$staff->id.'/reactivate')->assertForbidden();
        $this->delete('/business/members/'.$staff->id)->assertForbidden();

        $this->actingAs($staff)->withSession(['current_tenant_id' => $tenant->id]);
        $this->patch('/business/members/'.$manager->id.'/role', ['role' => 'staff'])->assertForbidden();
    }

    public function test_membership_lifecycle_cannot_cross_tenant_boundaries(): void
    {
        [$admin, $tenantA] = $this->workspace('membership-tenant-a@example.test');
        [, $tenantB] = $this->workspace('membership-tenant-b@example.test');
        $foreign = $this->member($tenantB, 'membership-foreign@example.test', 'staff');

        $this->actingAs($admin)->withSession(['current_tenant_id' => $tenantA->id]);
        $this->patch('/business/members/'.$foreign->id.'/role', ['role' => 'manager'])->assertNotFound();
        $this->post('/business/members/'.$foreign->id.'/suspend')->assertNotFound();
        $this->post('/business/members/'.$foreign->id.'/reactivate')->assertNotFound();
        $this->delete('/business/members/'.$foreign->id)->assertNotFound();

        $this->assertDatabaseHas('tenant_user', ['tenant_id' => $tenantB->id, 'user_id' => $foreign->id, 'role' => 'staff', 'status' => 'active']);
    }

    private function conversation(Tenant $tenant, int $assignedUserId): Conversation
    {
        return TenantContext::runForTenant($tenant->id, function () use ($assignedUserId): Conversation {
            $customer = Customer::query()->create(['whatsapp_wa_id' => '923001112233']);
            return Conversation::query()->create([
                'customer_id' => $customer->id,
                'status' => 'open',
                'automation_state' => 'HUMAN_ACTIVE',
                'assigned_user_id' => $assignedUserId,
            ]);
        });
    }

    private function workspace(string $email): array
    {
        $admin = User::query()->create(['name' => 'Workspace Admin', 'email' => $email, 'password' => 'StrongPassword123']);
        $admin->forceFill(['email_verified_at' => now()])->save();
        $tenant = Tenant::query()->create(['name' => 'Membership Workspace', 'slug' => str_replace(['@', '.'], '-', $email)]);
        $tenant->users()->attach($admin->id, ['role' => 'business_admin', 'status' => 'active']);

        return [$admin, $tenant];
    }

    private function member(Tenant $tenant, string $email, string $role): User
    {
        $member = User::query()->create(['name' => 'Workspace Member', 'email' => $email, 'password' => 'StrongPassword123']);
        $member->forceFill(['email_verified_at' => now()])->save();
        $tenant->users()->attach($member->id, ['role' => $role, 'status' => 'active']);

        return $member;
    }
}
