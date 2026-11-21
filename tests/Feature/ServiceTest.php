<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_admin_can_create_update_and_remove_a_service(): void
    {
        [$user, $tenant] = $this->workspace('service-admin@example.test');
        $this->actingAs($user)->withSession(['current_tenant_id' => $tenant->id]);

        $payload = [
            'name' => 'Haircut',
            'description' => 'Standard haircut',
            'price_amount' => '1500.00',
            'currency' => 'PKR',
            'duration_minutes' => 30,
            'buffer_before_minutes' => 5,
            'buffer_after_minutes' => 10,
            'active' => true,
        ];

        $this->post('/business/services', $payload)->assertRedirect();
        $service = TenantContext::runForTenant($tenant->id, fn () => Service::query()->firstOrFail());
        $this->assertSame('1500.00', $service->price_amount);

        $this->put('/business/services/'.$service->id, [...$payload, 'name' => 'Premium Haircut'])->assertRedirect();
        $this->assertDatabaseHas('services', [
            'id' => $service->id,
            'tenant_id' => $tenant->id,
            'name' => 'Premium Haircut',
            'duration_minutes' => 30,
            'buffer_after_minutes' => 10,
        ]);

        $this->delete('/business/services/'.$service->id)->assertRedirect();
        $this->assertDatabaseMissing('services', ['id' => $service->id]);
    }

    public function test_service_validation_rejects_invalid_currency_duration_and_buffers(): void
    {
        [$user] = $this->workspace('service-validation@example.test');
        $this->actingAs($user);

        $this->post('/business/services', [
            'name' => 'Invalid service',
            'description' => null,
            'price_amount' => '-1',
            'currency' => 'pkr',
            'duration_minutes' => 2,
            'buffer_before_minutes' => 500,
            'buffer_after_minutes' => 0,
            'active' => true,
        ])->assertSessionHasErrors([
            'price_amount',
            'currency',
            'duration_minutes',
            'buffer_before_minutes',
        ]);
        $this->assertDatabaseCount('services', 0);
    }

    public function test_services_are_isolated_between_tenants(): void
    {
        [, $tenantA] = $this->workspace('service-a@example.test');
        [, $tenantB] = $this->workspace('service-b@example.test');

        TenantContext::runForTenant($tenantA->id, function (): void {
            Service::query()->create($this->serviceAttributes('Tenant A service'));
        });
        TenantContext::runForTenant($tenantB->id, function (): void {
            $this->assertSame(0, Service::query()->count());
        });
    }

    public function test_staff_cannot_manage_services(): void
    {
        [$user, $tenant] = $this->workspace('service-staff@example.test', 'staff');
        $this->actingAs($user)->withSession(['current_tenant_id' => $tenant->id]);

        $this->get('/business/services')->assertForbidden();
        $this->post('/business/services', [])->assertForbidden();
    }

    private function serviceAttributes(string $name): array
    {
        return [
            'name' => $name,
            'description' => null,
            'price_amount' => '100.00',
            'currency' => 'USD',
            'duration_minutes' => 30,
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 0,
            'active' => true,
        ];
    }

    private function workspace(string $email, string $role = 'business_admin'): array
    {
        $user = User::query()->create([
            'name' => 'Service User',
            'email' => $email,
            'password' => 'StrongPassword123',
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $tenant = Tenant::query()->create([
            'name' => 'Service Workspace',
            'slug' => str_replace(['@', '.'], '-', $email),
        ]);
        $tenant->users()->attach($user->id, ['role' => $role, 'status' => 'active']);

        return [$user, $tenant];
    }
}
