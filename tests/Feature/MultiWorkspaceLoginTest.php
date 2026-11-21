<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MultiWorkspaceLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_redirects_users_with_multiple_active_workspaces_to_selection(): void
    {
        $user = User::query()->create([
            'name' => 'Multi Workspace User',
            'email' => 'multi@example.test',
            'password' => 'StrongPassword123',
        ]);
        $first = Tenant::query()->create(['name' => 'First Studio', 'slug' => 'first-studio']);
        $second = Tenant::query()->create(['name' => 'Second Studio', 'slug' => 'second-studio']);
        $first->users()->attach($user->id, ['role' => 'business_admin', 'status' => 'active']);
        $second->users()->attach($user->id, ['role' => 'staff', 'status' => 'active']);

        $this->post('/login', [
            'email' => 'multi@example.test',
            'password' => 'StrongPassword123',
        ])->assertRedirect(route('workspaces.index'))
            ->assertSessionMissing('current_tenant_id');
    }
}
