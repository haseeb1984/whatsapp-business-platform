<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantRelationshipTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_belong_to_a_tenant_with_a_role(): void
    {
        $user = User::query()->create([
            'name' => 'Workspace Owner',
            'email' => 'owner@example.test',
            'password' => 'test-password',
        ]);

        $tenant = Tenant::query()->create([
            'name' => 'Example Business',
            'slug' => 'example-business',
            'timezone' => 'Asia/Karachi',
            'locale' => 'en',
        ]);

        $tenant->users()->attach($user->id, [
            'role' => 'business_admin',
            'status' => 'active',
        ]);

        $this->assertNotEmpty($tenant->id);
        $this->assertTrue($tenant->users()->whereKey($user->id)->exists());
        $this->assertSame('business_admin', $tenant->users()->first()->pivot->role);
    }
}
