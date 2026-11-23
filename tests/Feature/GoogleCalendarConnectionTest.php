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

    public function test_user_cannot_map_another_tenants_staff_to_their_calendar(): void
    {
        Http::fake();

        [$userA, $tenantA] = $this->workspace('google-foreign-a@example.test');
        [, $tenantB] = $this->workspace('google-foreign-b@example.test');

        $foreignStaff = TenantContext::runForTenant($tenantB->id, fn () => Staff::query()->create([
            'name' => 'Tenant B Calendar Staff',
            'active' => true,
        ]));
        TenantContext::runForTenant($tenantA->id, fn () => GoogleCalendarConnection::query()->create([
            'access_token' => 'tenant-a-calendar-access',
            'refresh_token' => 'tenant-a-calendar-refresh',
            'token_expires_at' => now()->addHour(),
            'status' => 'connected',
            'default_calendar_id' => 'primary',
        ]));

        $this->actingAs($userA)->withSession(['current_tenant_id' => $tenantA->id]);
        $this->put('/business/calendar/staff/'.$foreignStaff->id, ['calendar_id' => 'primary'])->assertNotFound();

        Http::assertNothingSent();
        $this->assertDatabaseHas('staff_members', [
            'id' => $foreignStaff->id,
            'tenant_id' => $tenantB->id,
            'google_calendar_id' => null,
        ]);
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

    public function test_google_oauth_client_error_does_not_mark_customer_connection_for_reauthorization(): void
    {
        [, $tenant] = $this->workspace('google-client-error@example.test');
        config([
            'services.google.client_id' => 'misconfigured-client',
            'services.google.client_secret' => 'client-secret',
            'services.google.redirect_uri' => 'https://platform.example.test/business/calendar/callback',
        ]);

        TenantContext::runForTenant($tenant->id, fn () => GoogleCalendarConnection::query()->create([
            'access_token' => 'expired-access',
            'refresh_token' => 'valid-customer-refresh',
            'token_expires_at' => now()->subMinute(),
            'status' => 'connected',
            'default_calendar_id' => 'primary',
        ]));

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'error' => 'invalid_client',
                'error_description' => 'OAuth client configuration is invalid.',
            ], 401),
        ]);

        try {
            TenantContext::runForTenant($tenant->id, fn () => app(GoogleCalendarService::class)->freeBusy(
                ['primary'],
                '2026-12-01T00:00:00Z',
                '2026-12-02T00:00:00Z',
            ));
            $this->fail('An invalid OAuth client must fail the provider operation.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('HTTP 401', $exception->getMessage());
        }

        $connection = TenantContext::runForTenant($tenant->id, fn () => GoogleCalendarConnection::query()->firstOrFail());
        $this->assertSame('connected', $connection->status);
        $this->assertSame('valid-customer-refresh', $connection->refresh_token);
    }

    public function test_revoked_refresh_token_marks_connection_as_reauthorization_required(): void
    {
        [, $tenant] = $this->workspace('google-revoked-refresh@example.test');
        config([
            'services.google.client_id' => 'client-id',
            'services.google.client_secret' => 'client-secret',
            'services.google.redirect_uri' => 'https://platform.example.test/business/calendar/callback',
        ]);

        TenantContext::runForTenant($tenant->id, fn () => GoogleCalendarConnection::query()->create([
            'access_token' => 'expired-access',
            'refresh_token' => 'revoked-refresh',
            'token_expires_at' => now()->subMinute(),
            'status' => 'connected',
            'default_calendar_id' => 'primary',
        ]));

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'error' => 'invalid_grant',
                'error_description' => 'Token has been expired or revoked.',
            ], 400),
        ]);

        try {
            TenantContext::runForTenant($tenant->id, fn () => app(GoogleCalendarService::class)->freeBusy(
                ['primary'],
                '2026-12-01T00:00:00Z',
                '2026-12-02T00:00:00Z',
            ));
            $this->fail('A revoked refresh token must not produce a successful Calendar response.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('HTTP 400', $exception->getMessage());
        }

        $connection = TenantContext::runForTenant($tenant->id, fn () => GoogleCalendarConnection::query()->firstOrFail());
        $this->assertSame('reauthorization_required', $connection->status);
        $this->assertSame('revoked-refresh', $connection->refresh_token);

        try {
            TenantContext::runForTenant($tenant->id, fn () => app(GoogleCalendarService::class)->freeBusy(
                ['primary'],
                '2026-12-01T00:00:00Z',
                '2026-12-02T00:00:00Z',
            ));
            $this->fail('A connection requiring reauthorization must be rejected before another provider request.');
        } catch (\Illuminate\Validation\ValidationException) {
            // Expected: the connection is no longer considered active.
        }

        Http::assertSentCount(1);
    }

    public function test_reauthorization_does_not_reuse_a_revoked_refresh_token_when_google_omits_a_new_one(): void
    {
        [$user, $tenant] = $this->workspace('google-reconnect-no-refresh@example.test');
        TenantContext::runForTenant($tenant->id, fn () => GoogleCalendarConnection::query()->create([
            'connected_by_user_id' => $user->id,
            'google_account_email' => 'calendar-owner@example.test',
            'access_token' => 'old-access-token',
            'refresh_token' => 'revoked-refresh-token',
            'token_expires_at' => now()->subMinute(),
            'status' => 'reauthorization_required',
            'default_calendar_id' => 'primary',
        ]));

        config([
            'services.google.client_id' => 'client-id',
            'services.google.client_secret' => 'client-secret',
            'services.google.redirect_uri' => 'https://platform.example.test/business/calendar/callback',
        ]);

        $this->actingAs($user)->withSession(['current_tenant_id' => $tenant->id]);
        $start = $this->get('/business/calendar/connect')->assertRedirect();
        $state = $start->getSession()->get('google_calendar_oauth.state');

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'new-access-token',
                'expires_in' => 3600,
                'scope' => implode(' ', GoogleCalendarService::SCOPES),
            ]),
            'www.googleapis.com/oauth2/v2/userinfo' => Http::response([
                'email' => 'calendar-owner@example.test',
                'verified_email' => true,
            ]),
        ]);

        $this->get('/business/calendar/callback?state='.$state.'&code=authorization-code')
            ->assertRedirect('/business/calendar')
            ->assertSessionHasErrors('calendar');

        $connection = TenantContext::runForTenant($tenant->id, fn () => GoogleCalendarConnection::query()->firstOrFail());
        $this->assertSame('reauthorization_required', $connection->status);
        $this->assertSame('revoked-refresh-token', $connection->refresh_token);
        $this->assertSame('old-access-token', $connection->access_token);
    }

    public function test_reauthorization_with_a_new_refresh_token_restores_connected_state(): void
    {
        [$user, $tenant] = $this->workspace('google-reconnect-fresh-token@example.test');
        TenantContext::runForTenant($tenant->id, fn () => GoogleCalendarConnection::query()->create([
            'connected_by_user_id' => $user->id,
            'google_account_email' => 'calendar-owner@example.test',
            'access_token' => 'old-access-token',
            'refresh_token' => 'revoked-refresh-token',
            'token_expires_at' => now()->subMinute(),
            'status' => 'reauthorization_required',
            'default_calendar_id' => 'primary',
        ]));

        config([
            'services.google.client_id' => 'client-id',
            'services.google.client_secret' => 'client-secret',
            'services.google.redirect_uri' => 'https://platform.example.test/business/calendar/callback',
        ]);

        $this->actingAs($user)->withSession(['current_tenant_id' => $tenant->id]);
        $start = $this->get('/business/calendar/connect')->assertRedirect();
        $state = $start->getSession()->get('google_calendar_oauth.state');

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'fresh-access-token',
                'refresh_token' => 'fresh-refresh-token',
                'expires_in' => 3600,
                'scope' => implode(' ', GoogleCalendarService::SCOPES),
            ]),
            'www.googleapis.com/oauth2/v2/userinfo' => Http::response([
                'email' => 'calendar-owner@example.test',
                'verified_email' => true,
            ]),
        ]);

        $this->get('/business/calendar/callback?state='.$state.'&code=authorization-code')
            ->assertRedirect('/business/calendar')
            ->assertSessionHas('success');

        $connection = TenantContext::runForTenant($tenant->id, fn () => GoogleCalendarConnection::query()->firstOrFail());
        $this->assertSame('connected', $connection->status);
        $this->assertSame('fresh-access-token', $connection->access_token);
        $this->assertSame('fresh-refresh-token', $connection->refresh_token);
    }

    public function test_calendar_settings_exposes_reauthorization_required_state_to_the_ui(): void
    {
        [$user, $tenant] = $this->workspace('google-reauthorization-ui@example.test');
        TenantContext::runForTenant($tenant->id, fn () => GoogleCalendarConnection::query()->create([
            'access_token' => 'expired-access',
            'refresh_token' => 'revoked-refresh',
            'token_expires_at' => now()->subMinute(),
            'status' => 'reauthorization_required',
            'default_calendar_id' => 'primary',
        ]));

        $this->actingAs($user)->withSession(['current_tenant_id' => $tenant->id])
            ->get('/business/calendar')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Business/Calendar/Index')
                ->where('connection.connected', false)
                ->where('connection.status', 'reauthorization_required'));
    }

    public function test_clear_saved_authorization_removes_revoked_google_credentials(): void
    {
        [$user, $tenant] = $this->workspace('google-clear-revoked@example.test');
        TenantContext::runForTenant($tenant->id, fn () => GoogleCalendarConnection::query()->create([
            'access_token' => 'expired-access',
            'refresh_token' => 'revoked-refresh',
            'token_expires_at' => now()->subMinute(),
            'status' => 'reauthorization_required',
            'default_calendar_id' => 'primary',
        ]));

        Http::fake([
            'oauth2.googleapis.com/revoke' => Http::response(['error' => 'invalid_token'], 400),
        ]);

        $this->actingAs($user)->withSession(['current_tenant_id' => $tenant->id])
            ->delete('/business/calendar')
            ->assertRedirect()
            ->assertSessionHas('success');

        $connection = TenantContext::runForTenant($tenant->id, fn () => GoogleCalendarConnection::query()->firstOrFail());
        $this->assertSame('disconnected', $connection->status);
        $this->assertNull($connection->access_token);
        $this->assertNull($connection->refresh_token);
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
