<?php

namespace App\Http\Controllers;

use App\Models\GoogleCalendarConnection;
use App\Services\Google\GoogleCalendarService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class GoogleCalendarConnectionController extends Controller
{
    public function index(): Response
    {
        $connection = GoogleCalendarConnection::query()->first();

        return Inertia::render('Business/Calendar/Index', [
            'connection' => $connection ? [
                'connected' => $connection->status === 'connected',
                'account_email' => $connection->google_account_email,
                'default_calendar_id' => $connection->default_calendar_id,
                'connected_at' => $connection->connected_at?->toIso8601String(),
            ] : null,
            'oauth_configured' => filled(config('services.google.client_id'))
                && filled(config('services.google.client_secret'))
                && filled(config('services.google.redirect_uri')),
        ]);
    }

    public function connect(Request $request, GoogleCalendarService $calendar): RedirectResponse
    {
        $state = bin2hex(random_bytes(32));
        $request->session()->put('google_calendar_oauth', [
            'state' => $state,
            'tenant_id' => app(TenantContext::class)->id(),
            'user_id' => $request->user()->id,
            'created_at' => now()->timestamp,
        ]);

        return redirect()->away($calendar->authorizationUrl($state));
    }

    public function callback(Request $request, GoogleCalendarService $calendar): RedirectResponse
    {
        $expected = $request->session()->pull('google_calendar_oauth');
        $provided = $request->query('state');

        if (! is_array($expected)
            || ! is_string($provided)
            || ! is_string($expected['state'] ?? null)
            || ! hash_equals($expected['state'], $provided)
            || ($expected['tenant_id'] ?? null) !== app(TenantContext::class)->id()
            || ($expected['user_id'] ?? null) !== $request->user()->id
            || (int) ($expected['created_at'] ?? 0) < now()->subMinutes(10)->timestamp) {
            abort(403, 'Invalid or expired Google OAuth state.');
        }

        if ($request->filled('error')) {
            return redirect()->route('business.calendar.index')->withErrors([
                'calendar' => 'Google Calendar authorization was not completed.',
            ]);
        }

        $code = $request->query('code');
        if (! is_string($code) || $code === '') {
            return redirect()->route('business.calendar.index')->withErrors([
                'calendar' => 'Google did not return an authorization code.',
            ]);
        }

        $tokens = $calendar->exchangeAuthorizationCode($code);
        $userInfo = Http::withToken($tokens['access_token'])->acceptJson()->timeout(10)
            ->get('https://www.googleapis.com/oauth2/v2/userinfo');

        if (! $userInfo->successful() || ! is_string($userInfo->json('email'))) {
            throw new RuntimeException('Google account identity could not be verified.');
        }

        $connection = GoogleCalendarConnection::query()->first();
        $connection ??= new GoogleCalendarConnection();
        $connection->fill([
            'google_account_email' => $userInfo->json('email'),
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'] ?? $connection->refresh_token,
            'token_expires_at' => now()->addSeconds(max(60, (int) ($tokens['expires_in'] ?? 3600))),
            'scope' => $tokens['scope'] ?? null,
            'default_calendar_id' => $connection->default_calendar_id ?: 'primary',
            'status' => 'connected',
            'connected_at' => now(),
        ])->save();

        return redirect()->route('business.calendar.index')->with('success', 'Google Calendar connected successfully.');
    }

    public function disconnect(): RedirectResponse
    {
        $connection = GoogleCalendarConnection::query()->first();
        if ($connection) {
            $connection->forceFill([
                'access_token' => null,
                'refresh_token' => null,
                'token_expires_at' => null,
                'status' => 'disconnected',
                'connected_at' => null,
            ])->save();
        }

        return back()->with('success', 'Google Calendar disconnected.');
    }
}
