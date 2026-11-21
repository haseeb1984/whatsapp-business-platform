<?php

namespace Tests\Feature;

use App\Models\BusinessProfile;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_admin_can_create_and_update_their_business_profile(): void
    {
        [$user, $tenant] = $this->workspace('profile-admin@example.test');
        $this->actingAs($user)->withSession(['current_tenant_id' => $tenant->id]);

        $payload = [
            'display_name' => 'Northside Studio',
            'description' => 'Hair and beauty services.',
            'email' => 'hello@example.test',
            'phone' => '+923001234567',
            'website' => 'https://example.test',
            'address' => 'Karachi',
            'timezone' => 'Asia/Karachi',
            'locale' => 'en',
        ];

        $this->put('/business/profile', $payload)->assertRedirect();
        $this->assertDatabaseHas('business_profiles', [
            'tenant_id' => $tenant->id,
            'display_name' => 'Northside Studio',
        ]);
        $this->assertDatabaseHas('tenants', [
            'id' => $tenant->id,
            'timezone' => 'Asia/Karachi',
            'locale' => 'en',
        ]);

        $this->put('/business/profile', [...$payload, 'display_name' => 'Northside Studio Updated'])->assertRedirect();
        $this->assertDatabaseHas('business_profiles', [
            'tenant_id' => $tenant->id,
            'display_name' => 'Northside Studio Updated',
        ]);
    }

    public function test_profile_records_are_isolated_between_tenants(): void
    {
        [, $tenantA] = $this->workspace('profile-a@example.test');
        [, $tenantB] = $this->workspace('profile-b@example.test');

        \App\Support\Tenancy\TenantContext::runForTenant($tenantA->id, function (): void {
            BusinessProfile::query()->create(['display_name' => 'Tenant A']);
        });
        \App\Support\Tenancy\TenantContext::runForTenant($tenantB->id, function (): void {
            BusinessProfile::query()->create(['display_name' => 'Tenant B']);
        });
        \App\Support\Tenancy\TenantContext::runForTenant($tenantA->id, function (): void {
            $this->assertSame('Tenant A', BusinessProfile::query()->firstOrFail()->display_name);
            $this->assertSame(1, BusinessProfile::query()->count());
        });
    }

    public function test_staff_cannot_manage_business_profile(): void
    {
        [$user, $tenant] = $this->workspace('profile-staff@example.test', 'staff');
        $this->actingAs($user)->withSession(['current_tenant_id' => $tenant->id]);

        $this->get('/business/profile')->assertForbidden();
        $this->put('/business/profile', ['display_name' => 'Not allowed'])->assertForbidden();
    }

    private function workspace(string $email, string $role = 'business_admin'): array
    {
        $user = User::query()->create([
            'name' => 'Profile User',
            'email' => $email,
            'password' => 'StrongPassword123',
            'email_verified_at' => now(),
        ]);
        $tenant = Tenant::query()->create([
            'name' => 'Profile Workspace',
            'slug' => str_replace(['@', '.'], '-', $email),
        ]);
        $tenant->users()->attach($user->id, ['role' => $role, 'status' => 'active']);

        return [$user, $tenant];
    }
}
