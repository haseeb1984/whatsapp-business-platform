<?php

namespace Tests\\Feature;

use App\\Models\\BusinessProfile;
use App\\Models\\Tenant;
use App\\Models\\User;
use App\\Support\\Tenancy\\TenantContext;
use Illuminate\\Foundation\\Testing\\RefreshDatabase;
use Tests\\TestCase;

class BusinessProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_admin_can_create_and_update_their_business_profile(): void
    {
        [$user, $tenant] = $this->workspace('profile-admin@example.test');
        $this->actingAs($user);
        app()->instance(TenantContext::class, new TenantContext($tenant, 'business_admin'));

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

        $this->put('/business/profile', [...$payload, 'display_name' => 'Northside Studio Updated'])->assertRedirect();
        $this->assertDatabaseHas('business_profiles', ['tenant_id' => $tenant->id, 'display_name' => 'Northside Studio Updated']);
    }

    public function test_profile_records_are_isolated_between_tenants(): void
    {
        [$userA, $tenantA] = $this->workspace('profile-a@example.test');
        [$userB, $tenantB] = $this->workspace('profile-b@example.test');

        app()->instance(TenantContext::class, new TenantContext($tenantA, 'business_admin'));
        BusinessProfile::query()->create(['display_name' => 'Tenant A']);

        app()->instance(TenantContext::class, new TenantContext($tenantB, 'business_admin'));
        BusinessProfile::query()->create(['display_name' => 'Tenant B']);

        app()->instance(TenantContext::class, new TenantContext($tenantA, 'business_admin'));
        $this->assertSame('Tenant A', BusinessProfile::query()->firstOrFail()->display_name);
        $this->assertSame(1, BusinessProfile::query()->count());
    }

    public function test_staff_cannot_manage_business_profile(): void
    {
        [$user, $tenant] = $this->workspace('profile-staff@example.test', 'staff');
        $this->actingAs($user);

        $this->get('/business/profile')->assertForbidden();
        $this->put('/business/profile', ['display_name' => 'Not allowed'])->assertForbidden();
    }

    private function workspace(string $email, string $role = 'business_admin'): array
    {
        $user = User::query()->create(['name' => 'Profile User', 'email' => $email, 'password' => 'StrongPassword123']);
        $tenant = Tenant::query()->create(['name' => 'Profile Workspace', 'slug' => str_replace(['@', '.'], '-', $email)]);
        $tenant->users()->attach($user->id, ['role' => $role, 'status' => 'active']);

        return [$user, $tenant];
    }
}
