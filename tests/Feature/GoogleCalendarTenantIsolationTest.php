<?php

namespace Tests\Feature;

use App\Models\GoogleCalendarConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleCalendarTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_disconnect_only_revokes_and_clears_the_active_tenants_google_connection(): void
    {
        [$userA, $tenantA] = $this->workspace('calendar-disconnect-a@example.test');
        [, $tenantB] = $this->workspace('calendar-disconnect-b@example.test');

        $connectionA = TenantContext::runForTenant($tenantA->id, fn () => GoogleCalendarConnection::query()->create([
            'access_token' => 'tenant-a-access-token',
            'refresh_token' => 'tenant-a-refresh-token',
            'token_expires_at' => now()->addHour(),
            'status' => 'connected',
            'default_calendar_id' => 'primary',
        ]));

        $connectionB = TenantContext::runForTenant($tenantB->id, fn () => GoogleCalendarConnection::query()->create([
            'access_token' => 'tenant-b-access-token',
            'refresh_token' => 'tenant-b-refresh-token',
            'token_expires_at' => now()->addHour(),
            'status' => 'connected',
            'default_calendar_id' => 'tenant-b-calendar@example.test',
        ]));

        Http::fake([
            'oauth2.googleapis.com/revoke' => Http::response([], 200),
        ]);

        $this->actingAs($userA)->withSession(['current_tenant_id' => $tenantA->id])
            ->delete('/business/calendar')
            ->assertRedirect()
            ->assertSessionHas('success');

        $connectionA = TenantContext::runForTenant($tenantA->id, fn () => GoogleCalendarConnection::query()->findOrFail($connectionA->id));
        $connectionB = TenantContext::runForTenant($tenantB->id, fn () => GoogleCalendarConnection::query()->findOrFail($connectionB->id));

        $this->assertSame('disconnected', $connectionA->status);
        $this->assertNull($connectionA->access_token);
        $this->assertNull($connectionA->refresh_token);
        $this->assertSame('connected', $connectionB->status);
        $this->assertSame('tenant-b-access-token', $connectionB->access_token);
        $this->assertSame('tenant-b-refresh-token', $connectionB->refresh_token);
        $this->assertSame('tenant-b-calendar@example.test', $connectionB->default_calendar_id);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'https://oauth2.googleapis.com/revoke'
            && $request['token'] === 'tenant-a-refresh-token');
    }


    public function test_calendar_list_uses_only_the_active_tenants_google_credentials(): void
    {
        [$userA, $tenantA] = $this->workspace('calendar-list-a@example.test');
        [, $tenantB] = $this->workspace('calendar-list-b@example.test');

        TenantContext::runForTenant($tenantA->id, fn () => GoogleCalendarConnection::query()->create([
            'access_token' => 'tenant-a-calendar-access',
            'refresh_token' => 'tenant-a-calendar-refresh',
            'token_expires_at' => now()->addHour(),
            'status' => 'connected',
            'default_calendar_id' => 'primary',
        ]));
        TenantContext::runForTenant($tenantB->id, fn () => GoogleCalendarConnection::query()->create([
            'access_token' => 'tenant-b-calendar-access',
            'refresh_token' => 'tenant-b-calendar-refresh',
            'token_expires_at' => now()->addHour(),
            'status' => 'connected',
            'default_calendar_id' => 'tenant-b-primary',
        ]));

        Http::fake([
            'www.googleapis.com/calendar/v3/users/me/calendarList*' => Http::response([
                'items' => [['id' => 'primary', 'summary' => 'Workspace A', 'accessRole' => 'owner']],
            ]),
        ]);

        $this->actingAs($userA)->withSession(['current_tenant_id' => $tenantA->id])
            ->getJson('/business/calendar/calendars')
            ->assertOk()
            ->assertJsonPath('calendars.0.id', 'primary');

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'https://www.googleapis.com/calendar/v3/users/me/calendarList'
            && $request->hasHeader('Authorization', 'Bearer tenant-a-calendar-access'));
    }

    private function workspace(string $email): array
    {
        $user = User::query()->create([
            'name' => 'Calendar Isolation User',
            'email' => $email,
            'password' => 'StrongPassword123',
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        $tenant = Tenant::query()->create([
            'name' => 'Calendar Isolation Workspace',
            'slug' => str_replace(['@', '.'], '-', $email),
        ]);
        $tenant->users()->attach($user->id, ['role' => 'business_admin', 'status' => 'active']);

        return [$user, $tenant];
    }
}
