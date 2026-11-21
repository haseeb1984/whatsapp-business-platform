<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireTenantRole;
use App\Http\Middleware\ResolveTenant;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TenantRoleAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', 'auth', ResolveTenant::class, RequireTenantRole::class.':business_admin,manager'])
            ->get('/_test/admin-only', fn () => response()->json(['role' => app(TenantContext::class)->role()]));
    }

    public function test_business_admin_can_access_role_protected_tenant_route(): void
    {
        [$user, $tenant] = $this->makeMembership('business_admin');

        $this->actingAs($user)
            ->withSession(['current_tenant_id' => $tenant->id])
            ->getJson('/_test/admin-only')
            ->assertOk()
            ->assertJsonPath('role', 'business_admin');
    }

    public function test_staff_cannot_access_admin_only_tenant_route(): void
    {
        [$user, $tenant] = $this->makeMembership('staff');

        $this->actingAs($user)
            ->withSession(['current_tenant_id' => $tenant->id])
            ->getJson('/_test/admin-only')
            ->assertForbidden();
    }

    private function makeMembership(string $role): array
    {
        $user = User::query()->create([
            'name' => 'Tenant User',
            'email' => $role.'@example.test',
            'password' => 'StrongPassword123',
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $tenant = Tenant::query()->create([
            'name' => 'Test Business',
            'slug' => 'test-'.$role,
        ]);
        $tenant->users()->attach($user->id, ['role' => $role, 'status' => 'active']);

        return [$user, $tenant];
    }
}
