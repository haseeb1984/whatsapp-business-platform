<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppTemplate;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppTemplateSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_admin_can_sync_meta_templates_into_their_workspace(): void
    {
        config(['services.meta.graph_api_version' => 'v24.0']);
        Http::fake([
            'https://graph.facebook.com/v24.0/987654321098765/message_templates*' => Http::response([
                'data' => [[
                    'id' => 'template-provider-001',
                    'name' => 'appointment_reminder',
                    'language' => 'en_US',
                    'category' => 'UTILITY',
                    'status' => 'APPROVED',
                    'components' => [['type' => 'BODY', 'text' => 'Your appointment is {{1}}.']],
                ]],
            ], 200),
        ]);

        [$user, $tenant] = $this->workspace('template-admin@example.test');
        $connection = TenantContext::runForTenant($tenant->id, fn () => WhatsAppConnection::query()->create([
            'phone_number_id' => '123456789012345',
            'waba_id' => '987654321098765',
            'access_token' => 'long-lived-meta-access-token-for-tests',
            'status' => 'active',
            'verified_at' => now(),
        ]));

        $this->actingAs($user)->withSession(['current_tenant_id' => $tenant->id])
            ->post('/business/whatsapp/'.$connection->id.'/templates/sync')
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $template = TenantContext::runForTenant($tenant->id, fn () => WhatsAppTemplate::query()->firstOrFail());
        $this->assertSame('appointment_reminder', $template->name);
        $this->assertSame('APPROVED', $template->status);
        $this->assertSame('en_US', $template->language);
        $this->assertSame('Your appointment is {{1}}.', $template->components[0]['text']);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer long-lived-meta-access-token-for-tests'));
    }

    public function test_pending_connection_cannot_sync_templates(): void
    {
        [$user, $tenant] = $this->workspace('template-pending@example.test');
        $connection = TenantContext::runForTenant($tenant->id, fn () => WhatsAppConnection::query()->create([
            'phone_number_id' => '123456789012346',
            'waba_id' => '987654321098766',
            'access_token' => 'long-lived-meta-access-token-for-tests',
            'status' => 'pending',
        ]));

        $this->actingAs($user)->withSession(['current_tenant_id' => $tenant->id])
            ->post('/business/whatsapp/'.$connection->id.'/templates/sync')
            ->assertForbidden();

        $this->assertDatabaseCount('whatsapp_templates', 0);
    }

    public function test_meta_template_sync_failure_does_not_overwrite_existing_catalog(): void
    {
        config(['services.meta.graph_api_version' => 'v24.0']);
        Http::fake(['https://graph.facebook.com/*' => Http::response(['error' => ['message' => 'Denied']], 403)]);

        [$user, $tenant] = $this->workspace('template-failure@example.test');
        $connection = TenantContext::runForTenant($tenant->id, function () {
            $connection = WhatsAppConnection::query()->create([
                'phone_number_id' => '123456789012347',
                'waba_id' => '987654321098767',
                'access_token' => 'long-lived-meta-access-token-for-tests',
                'status' => 'active',
                'verified_at' => now(),
            ]);
            WhatsAppTemplate::query()->create([
                'whatsapp_connection_id' => $connection->id,
                'provider_template_id' => 'existing-template',
                'name' => 'existing_template',
                'language' => 'en_US',
                'category' => 'UTILITY',
                'status' => 'APPROVED',
                'components' => [],
                'synced_at' => now(),
            ]);

            return $connection;
        });

        $this->actingAs($user)->withSession(['current_tenant_id' => $tenant->id])
            ->post('/business/whatsapp/'.$connection->id.'/templates/sync')
            ->assertSessionHasErrors('templates');

        $this->assertDatabaseCount('whatsapp_templates', 1);
    }

    private function workspace(string $email): array
    {
        $user = User::query()->create([
            'name' => 'Template Admin',
            'email' => $email,
            'password' => 'StrongPassword123',
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $tenant = Tenant::query()->create([
            'name' => 'Template Workspace',
            'slug' => str_replace(['@', '.'], '-', $email),
        ]);
        $tenant->users()->attach($user->id, ['role' => 'business_admin', 'status' => 'active']);

        return [$user, $tenant];
    }
}
