<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveTenant;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TenantContextMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', 'auth', ResolveTenant::class])
            ->get('/_test/tenant-context', fn () => response()->json([
                'tenant_id' => app(TenantContext::class)->id(),
            ]));
    }

    public function test_it_resolves_the_only_active_membership_without_a_manual_selection(): void
    {
        $user = User::query()->create([
            'name' => 'Workspace Owner',
            'email' => 'owner@example.test',
            'password' => 'test-password',
        ]);
        $tenant = Tenant::query()->create([
            'name' => 'Example Business',
            'slug' => 'example-business',
        ]);
        $tenant->users()->attach($user->id, ['role' => 'business_admin', 'status' => 'active']);

        $this->actingAs($user)
            ->getJson('/_test/tenant-context')
            ->assertOk()
            ->assertJsonPath('tenant_id', $tenant->id);
    }

    public function test_it_does_not_allow_a_user_to_select_another_tenants_context(): void
    {
        $user = User::query()->create([
            'name' => 'Workspace Owner',
            'email' => 'owner@example.test',
            'password' => 'test-password',
        ]);
        $ownTenant = Tenant::query()->create(['name' => 'Own Business', 'slug' => 'own-business']);
        $otherTenant = Tenant::query()->create(['name' => 'Other Business', 'slug' => 'other-business']);
        $ownTenant->users()->attach($user->id, ['role' => 'business_admin', 'status' => 'active']);

        $this->actingAs($user)
            ->withSession(['current_tenant_id' => $otherTenant->id])
            ->getJson('/_test/tenant-context')
            ->assertNotFound();
    }

    public function test_it_requires_explicit_selection_when_a_user_has_multiple_active_tenants(): void
    {
        $user = User::query()->create([
            'name' => 'Workspace Owner',
            'email' => 'owner@example.test',
            'password' => 'test-password',
        ]);
        $first = Tenant::query()->create(['name' => 'First Business', 'slug' => 'first-business']);
        $second = Tenant::query()->create(['name' => 'Second Business', 'slug' => 'second-business']);
        $first->users()->attach($user->id, ['role' => 'business_admin', 'status' => 'active']);
        $second->users()->attach($user->id, ['role' => 'business_admin', 'status' => 'active']);

        $this->actingAs($user)
            ->getJson('/_test/tenant-context')
            ->assertStatus(409);
    }
}
