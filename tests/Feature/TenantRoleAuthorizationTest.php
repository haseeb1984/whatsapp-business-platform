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

    public function test_staff_is_forbidden_from_real_business_configuration_routes(): void
    {
        [$user, $tenant] = $this->makeMembership('staff');

        $this->actingAs($user)->withSession(['current_tenant_id' => $tenant->id]);

        $this->get('/business/profile')->assertForbidden();
        $this->put('/business/profile', ['name' => 'Attempted takeover'])->assertForbidden();
        $this->post('/business/faqs', [
            'question' => 'Can I book?',
            'answer' => 'Yes',
        ])->assertForbidden();
        $this->post('/business/whatsapp', [
            'phone_number_id' => '123456789012345',
            'waba_id' => '987654321098765',
            'access_token' => 'not-a-real-token',
        ])->assertForbidden();

        $this->assertDatabaseMissing('faqs', ['question' => 'Can I book?']);
        $this->assertDatabaseCount('whatsapp_connections', 0);
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
