<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceSwitchingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_switch_between_only_their_active_workspaces(): void
    {
        $user = User::query()->create([
            'name' => 'Workspace User',
            'email' => 'user@example.test',
            'password' => 'StrongPassword123',
        ]);
        $first = Tenant::query()->create(['name' => 'First Studio', 'slug' => 'first-studio']);
        $second = Tenant::query()->create(['name' => 'Second Studio', 'slug' => 'second-studio']);
        $first->users()->attach($user->id, ['role' => 'business_admin', 'status' => 'active']);
        $second->users()->attach($user->id, ['role' => 'staff', 'status' => 'active']);

        $this->actingAs($user)
            ->withSession(['current_tenant_id' => $first->id])
            ->post('/workspaces/switch', ['tenant_id' => $second->id])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('current_tenant_id', $second->id);
    }

    public function test_a_user_cannot_switch_to_a_workspace_without_active_membership(): void
    {
        $user = User::query()->create([
            'name' => 'Workspace User',
            'email' => 'user@example.test',
            'password' => 'StrongPassword123',
        ]);
        $own = Tenant::query()->create(['name' => 'Own Studio', 'slug' => 'own-studio']);
        $other = Tenant::query()->create(['name' => 'Other Studio', 'slug' => 'other-studio']);
        $own->users()->attach($user->id, ['role' => 'business_admin', 'status' => 'active']);

        $this->actingAs($user)
            ->post('/workspaces/switch', ['tenant_id' => $other->id])
            ->assertNotFound();
    }

    public function test_workspace_selection_hides_suspended_tenants_even_with_active_membership(): void
    {
        $user = User::query()->create([
            'name' => 'Workspace User',
            'email' => 'suspended-tenant@example.test',
            'password' => 'StrongPassword123',
        ]);
        $active = Tenant::query()->create(['name' => 'Active Studio', 'slug' => 'active-studio']);
        $suspended = Tenant::query()->create([
            'name' => 'Suspended Studio',
            'slug' => 'suspended-studio',
            'status' => 'suspended',
        ]);
        $active->users()->attach($user->id, ['role' => 'business_admin', 'status' => 'active']);
        $suspended->users()->attach($user->id, ['role' => 'business_admin', 'status' => 'active']);

        $this->actingAs($user)
            ->get('/workspaces')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Workspaces/Index')
                ->has('workspaces', 1)
                ->where('workspaces.0.id', $active->id));

        $this->actingAs($user)
            ->post('/workspaces/switch', ['tenant_id' => $suspended->id])
            ->assertNotFound();
    }

    public function test_workspace_selection_lists_only_active_memberships(): void
    {
        $user = User::query()->create([
            'name' => 'Workspace User',
            'email' => 'user@example.test',
            'password' => 'StrongPassword123',
        ]);
        $active = Tenant::query()->create(['name' => 'Active Studio', 'slug' => 'active-studio']);
        $suspended = Tenant::query()->create(['name' => 'Suspended Studio', 'slug' => 'suspended-studio']);
        $active->users()->attach($user->id, ['role' => 'business_admin', 'status' => 'active']);
        $suspended->users()->attach($user->id, ['role' => 'business_admin', 'status' => 'suspended']);

        $this->actingAs($user)
            ->get('/workspaces')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Workspaces/Index')
                ->has('workspaces', 1)
                ->where('workspaces.0.id', $active->id));
    }
}
