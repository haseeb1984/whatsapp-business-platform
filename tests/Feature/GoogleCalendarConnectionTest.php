<?php

namespace Tests\Feature;

use App\Models\GoogleCalendarConnection;
use App\Models\Staff;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Google\GoogleCalendarService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleCalendarConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_admin_can_complete_google_oauth_and_tokens_are_encrypted(): void
    {
        [$user, $tenant] = $this->workspace('google-admin@example.test');
        $this->actingAs($user)->withSession(['current_tenant_id' => $tenant->id]);
        config([
            'services.google.client_id' => 'client-id',
            'services.google.client_secret' => 'client-secret',
            'services.google.redirect_uri' => 'https://platform.example.test/business/calendar/callback',
        ]);

        $start = $this->get('/business/calendar/connect')->assertRedirect();
        $state = $start->getSession()->get('google_calendar_oauth.state');
        $this->assertIsString($state);
        $this->assertStringContainsString('accounts.google.com/o/oauth2/v2/auth', $start->headers->get('Location'));
        $this->assertStringContainsString('calendar.events', $start->headers->get('Location'));
        $query = [];
        parse_str((string) parse_url($start->headers->get('Location'), PHP_URL_QUERY), $query);
        $verifier = $start->getSession()->get('google_calendar_oauth.code_verifier');
        $expectedChallenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $this->assertSame('S256', $query['code_challenge_method'] ?? null);
        $this->assertSame($expectedChallenge, $query['code_challenge'] ?? null);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'plain-access-secret',
                'refresh_token' => 'plain-refresh-secret',
                'expires_in' => 3600,
                'scope' => implode(' ', GoogleCalendarService::SCOPES),
            ]),
            'www.googleapis.com/oauth2/v2/userinfo' => Http::response(['email' => 'calendar-owner@example.test', 'verified_email' => true]),
        ]);

        $this->get('/business/calendar/callback?state='.$state.'&code=authorization-code')
            ->assertRedirect('/business/calendar')
            ->assertSessionHas('success');

        $connection = TenantContext::runForTenant($tenant->id, fn () => GoogleCalendarConnection::query()->firstOrFail());
        $this->assertSame('connected', $connection->status);
        $this->assertSame($user->id, $connection->connected_by_user_id);
        $this->assertSame('calendar-owner@example.test', $connection->google_account_email);
        $this->assertSame('plain-access-secret', $connection->access_token);
        $this->assertSame('plain-refresh-secret', $connection->refresh_token);
        $storedToken = DB::table('google_calendar_connections')->where('id', $connection->id)->value('access_token');
        $this->assertNotSame('plain-access-secret', $storedToken);
        $this->assertNotSame('plain-refresh-secret', DB::table('google_calendar_connections')->where('id', $connection->id)->value('refresh_token'));
        Http::assertSent(fn ($request) => $request->url() === 'https://oauth2.googleapis.com/token'
            && $request['code_verifier'] === $verifier);
    }


    public function test_business_admin_can_list_writable_calendars_and_map_staff_calendar(): void
    {
        [$user, $tenant] = $this->workspace('google-calendar-mapping@example.test');
        $staff = TenantContext::runForTenant($tenant->id, fn () => Staff::query()->create([
            'name' => 'Mapped Staff',
            'active' => true,
        ]));
        TenantContext::runForTenant($tenant->id, fn () => GoogleCalendarConnection::query()->create([
            'access_token' => 'calendar-access',
            'refresh_token' => 'calendar-refresh',
            'token_expires_at' => now()->addHour(),
            'status' => 'connected',
            'default_calendar_id' => 'primary',
        ]));

        Http::fake([
            'www.googleapis.com/calendar/v3/users/me/calendarList*' => Http::response([
                'items' => [
                    ['id' => 'primary', 'summary' => 'Primary', 'primary' => true, 'accessRole' => 'owner'],
                    ['id' => 'staff-calendar@example.test', 'summary' => 'Staff Calendar', 'accessRole' => 'writer'],
                ],
            ]),
        ]);

        $this->actingAs($user)->withSession(['current_tenant_id' => $tenant->id]);
        $this->getJson('/business/calendar/calendars')
            ->assertOk()
            ->assertJsonCount(2, 'calendars');

        $this->put('/business/calendar/default', ['calendar_id' => 'staff-calendar@example.test'])->assertRedirect();
        $this->put('/business/calendar/staff/'.$staff->id, ['calendar_id' => 'primary'])->assertRedirect();

        $connection = TenantContext::runForTenant($tenant->id, fn () => GoogleCalendarConnection::query()->firstOrFail());
        $mappedStaff = TenantContext::runForTenant($tenant->id, fn () => Staff::query()->findOrFail($staff->id));
        $this->assertSame('staff-calendar@example.test', $connection->default_calendar_id);
        $this->assertSame('primary', $mappedStaff->google_calendar_id);
    }

    public function test_callback_rejects_invalid_or_replayed_oauth_state(): void
    {
        [$user, $tenant] = $this->workspace('google-state@example.test');
        $this->actingAs($user)->withSession(['current_tenant_id' => $tenant->id]);

        $this->get('/business/calendar/callback?state=attacker&code=authorization-code')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_staff_cannot_manage_google_calendar_connection(): void
    {
        [$user, $tenant] = $this->workspace('google-staff@example.test', 'staff');
        $this->actingAs($user)->withSession(['current_tenant_id' => $tenant->id]);

        $this->get('/business/calendar')->assertForbidden();
        $this->get('/business/calendar/connect')->assertForbidden();
    }

    public function test_calendar_api_refreshes_expired_access_token_before_freebusy_request(): void
    {
        [$user, $tenant] = $this->workspace('google-refresh@example.test');
        config([
            'services.google.client_id' => 'client-id',
            'services.google.client_secret' => 'client-secret',
            'services.google.redirect_uri' => 'https://platform.example.test/business/calendar/callback',
        ]);

        TenantContext::runForTenant($tenant->id, function (): void {
            GoogleCalendarConnection::query()->create([
                'access_token' => 'expired-access',
                'refresh_token' => 'refresh-secret',
                'token_expires_at' => now()->subMinute(),
                'status' => 'connected',
                'default_calendar_id' => 'primary',
            ]);
        });

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'fresh-access', 'expires_in' => 3600]),
            'www.googleapis.com/calendar/v3/freeBusy' => Http::response([
                'calendars' => ['primary' => ['busy' => []]],
            ]),
        ]);

        $busy = TenantContext::runForTenant($tenant->id, fn () => app(GoogleCalendarService::class)->freeBusy(
            ['primary'],
            '2026-12-01T00:00:00Z',
            '2026-12-02T00:00:00Z',
        ));

        $this->assertSame([], $busy['primary']['busy']);
        Http::assertSent(fn ($request) => $request->url() === 'https://www.googleapis.com/calendar/v3/freeBusy'
            && $request->hasHeader('Authorization', 'Bearer fresh-access'));
    }


    public function test_disconnect_revokes_google_token_and_clears_encrypted_credentials(): void
    {
        [$user, $tenant] = $this->workspace('google-disconnect@example.test');
        TenantContext::runForTenant($tenant->id, fn () => GoogleCalendarConnection::query()->create([
            'connected_by_user_id' => $user->id,
            'access_token' => 'access-to-revoke',
            'refresh_token' => 'refresh-to-revoke',
            'token_expires_at' => now()->addHour(),
            'status' => 'connected',
        ]));

        Http::fake([
            'oauth2.googleapis.com/revoke' => Http::response([], 200),
        ]);

        $this->actingAs($user)->withSession(['current_tenant_id' => $tenant->id])
            ->delete('/business/calendar')
            ->assertRedirect()
            ->assertSessionHas('success');

        $connection = TenantContext::runForTenant($tenant->id, fn () => GoogleCalendarConnection::query()->firstOrFail());
        $this->assertSame('disconnected', $connection->status);
        $this->assertNull($connection->access_token);
        $this->assertNull($connection->refresh_token);
        Http::assertSent(fn ($request) => $request->url() === 'https://oauth2.googleapis.com/revoke'
            && $request['token'] === 'refresh-to-revoke');
    }

    public function test_read_only_google_calendar_cannot_be_selected_for_bookings(): void
    {
        [$user, $tenant] = $this->workspace('google-readonly@example.test');
        TenantContext::runForTenant($tenant->id, fn () => GoogleCalendarConnection::query()->create([
            'access_token' => 'calendar-access',
            'refresh_token' => 'calendar-refresh',
            'token_expires_at' => now()->addHour(),
            'status' => 'connected',
            'default_calendar_id' => 'primary',
        ]));

        Http::fake([
            'www.googleapis.com/calendar/v3/users/me/calendarList*' => Http::response([
                'items' => [
                    ['id' => 'primary', 'summary' => 'Primary', 'primary' => true, 'accessRole' => 'owner'],
                    ['id' => 'readonly@example.test', 'summary' => 'Read Only', 'accessRole' => 'reader'],
                ],
            ]),
        ]);

        $this->actingAs($user)->withSession(['current_tenant_id' => $tenant->id])
            ->put('/business/calendar/default', ['calendar_id' => 'readonly@example.test'])
            ->assertStatus(422);

        $connection = TenantContext::runForTenant($tenant->id, fn () => GoogleCalendarConnection::query()->firstOrFail());
        $this->assertSame('primary', $connection->default_calendar_id);
    }

    private function workspace(string $email, string $role = 'business_admin'): array
    {
        $user = User::query()->create([
            'name' => 'Google Calendar User',
            'email' => $email,
            'password' => 'StrongPassword123',
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $tenant = Tenant::query()->create([
            'name' => 'Google Calendar Workspace',
            'slug' => str_replace(['@', '.'], '-', $email),
        ]);
        $tenant->users()->attach($user->id, ['role' => $role, 'status' => 'active']);

        return [$user, $tenant];
    }
}
