<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\WhatsAppConnection;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_admin_can_save_a_connection_with_an_encrypted_token(): void
    {
        [$user, $tenant] = $this->workspace('wa-admin@example.test');
        $this->actingAs($user)->withSession(['current_tenant_id' => $tenant->id]);

        $this->post('/business/whatsapp', [
            'phone_number_id' => '123456789012345',
            'waba_id' => '987654321098765',
            'display_phone_number' => '+1 555 0100',
            'verified_name' => 'Example Business',
            'access_token' => 'super-secret-meta-access-token',
        ])->assertRedirect();

        $connection = TenantContext::runForTenant($tenant->id, fn () => WhatsAppConnection::query()->firstOrFail());
        $this->assertSame('super-secret-meta-access-token', $connection->access_token);
        $this->assertNotSame('super-secret-meta-access-token', $connection->getRawOriginal('access_token'));
        $this->assertSame('pending', $connection->status);

        $this->get('/business/whatsapp')
            ->assertOk()
            ->assertDontSee('super-secret-meta-access-token')
            ->assertSee('123456789012345');
    }

    public function test_meta_verification_activates_a_connection_only_after_matching_phone_id(): void
    {
        config(['services.meta.graph_api_version' => 'v24.0']);
        Http::fake([
            'https://graph.facebook.com/v24.0/123456789012345*' => Http::response([
                'id' => '123456789012345',
                'display_phone_number' => '+1 555 0100',
                'verified_name' => 'Example Verified Business',
            ], 200),
            'https://graph.facebook.com/v24.0/987654321098765/phone_numbers*' => Http::response([
                'data' => [['id' => '123456789012345']],
            ], 200),
        ]);

        [$user, $tenant] = $this->workspace('wa-verify@example.test');
        $connection = TenantContext::runForTenant($tenant->id, fn () => WhatsAppConnection::query()->create([
            'phone_number_id' => '123456789012345',
            'waba_id' => '987654321098765',
            'access_token' => 'verified-long-lived-access-token',
            'status' => 'pending',
        ]));
        $this->actingAs($user)->withSession(['current_tenant_id' => $tenant->id]);

        $this->post('/business/whatsapp/'.$connection->id.'/verify')->assertRedirect();
        $verified = TenantContext::runForTenant($tenant->id, fn () => WhatsAppConnection::query()->findOrFail($connection->id));
        $this->assertSame('active', $verified->status);
        $this->assertSame('Example Verified Business', $verified->verified_name);
        $this->assertNotNull($verified->verified_at);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer verified-long-lived-access-token'));
    }

    public function test_meta_verification_failure_keeps_the_connection_pending(): void
    {
        config(['services.meta.graph_api_version' => 'v24.0']);
        Http::fake(['https://graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid token']], 401)]);

        [$user, $tenant] = $this->workspace('wa-verify-fail@example.test');
        $connection = TenantContext::runForTenant($tenant->id, fn () => WhatsAppConnection::query()->create([
            'phone_number_id' => '123456789012346',
            'waba_id' => '987654321098766',
            'access_token' => 'invalid-long-lived-access-token',
            'status' => 'pending',
        ]));
        $this->actingAs($user)->withSession(['current_tenant_id' => $tenant->id]);

        $this->post('/business/whatsapp/'.$connection->id.'/verify')->assertSessionHasErrors('connection');
        $this->assertDatabaseHas('whatsapp_connections', ['id' => $connection->id, 'status' => 'pending']);
    }

    public function test_phone_number_id_cannot_be_claimed_by_another_tenant(): void
    {
        [, $tenantA] = $this->workspace('wa-owner-a@example.test');
        [$userB, $tenantB] = $this->workspace('wa-owner-b@example.test');

        TenantContext::runForTenant($tenantA->id, function (): void {
            WhatsAppConnection::query()->create([
                'phone_number_id' => '123450000000001',
                'waba_id' => '987650000000001',
                'access_token' => 'first-long-lived-access-token',
                'status' => 'pending',
            ]);
        });

        $this->actingAs($userB)->withSession(['current_tenant_id' => $tenantB->id]);
        $this->post('/business/whatsapp', [
            'phone_number_id' => '123450000000001',
            'waba_id' => '987650000000002',
            'access_token' => 'second-long-lived-access-token',
        ])->assertSessionHasErrors('phone_number_id');

        $this->assertDatabaseCount('whatsapp_connections', 1);
    }

    public function test_user_cannot_verify_another_tenants_whatsapp_connection(): void
    {
        Http::fake();

        [$userA, $tenantA] = $this->workspace('wa-foreign-a@example.test');
        [, $tenantB] = $this->workspace('wa-foreign-b@example.test');
        $foreignConnection = TenantContext::runForTenant($tenantB->id, fn () => WhatsAppConnection::query()->create([
            'phone_number_id' => '123450000000099',
            'waba_id' => '987650000000099',
            'access_token' => 'tenant-b-secret-access-token',
            'status' => 'pending',
        ]));

        $this->actingAs($userA)->withSession(['current_tenant_id' => $tenantA->id]);

        $this->get('/business/whatsapp')->assertOk()->assertDontSee('123450000000099');
        $this->put('/business/whatsapp/'.$foreignConnection->id, [])->assertNotFound();
        $this->post('/business/whatsapp/'.$foreignConnection->id.'/verify')->assertNotFound();
        $this->post('/business/whatsapp/'.$foreignConnection->id.'/templates/sync')->assertNotFound();
        $this->delete('/business/whatsapp/'.$foreignConnection->id)->assertNotFound();

        Http::assertNothingSent();
        $this->assertDatabaseHas('whatsapp_connections', [
            'id' => $foreignConnection->id,
            'tenant_id' => $tenantB->id,
            'status' => 'pending',
        ]);
    }

    public function test_staff_cannot_view_or_create_whatsapp_connections(): void
    {
        [$user, $tenant] = $this->workspace('wa-staff@example.test', 'staff');
        $this->actingAs($user)->withSession(['current_tenant_id' => $tenant->id]);

        $this->get('/business/whatsapp')->assertForbidden();
        $this->post('/business/whatsapp', [])->assertForbidden();
    }

    private function workspace(string $email, string $role = 'business_admin'): array
    {
        $user = User::query()->create([
            'name' => 'WhatsApp User',
            'email' => $email,
            'password' => 'StrongPassword123',
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $tenant = Tenant::query()->create([
            'name' => 'WhatsApp Workspace',
            'slug' => str_replace(['@', '.'], '-', $email),
        ]);
        $tenant->users()->attach($user->id, ['role' => $role, 'status' => 'active']);

        return [$user, $tenant];
    }
}
