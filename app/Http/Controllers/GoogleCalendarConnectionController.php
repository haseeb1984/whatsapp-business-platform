<?php

namespace App\Http\Controllers;

use App\Models\GoogleCalendarConnection;
use App\Models\Staff;
use App\Services\Google\GoogleCalendarService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;
use Inertia\Inertia;
use Inertia\Response;

class GoogleCalendarConnectionController extends Controller
{
    public function index(): Response
    {
        $connection = GoogleCalendarConnection::query()->first();

        return Inertia::render('Business/Calendar/Index', [
            'connection' => $connection ? [
                'connected' => $connection->status === 'connected',
                'status' => $connection->status,
                'account_email' => $connection->google_account_email,
                'default_calendar_id' => $connection->default_calendar_id,
                'connected_at' => $connection->connected_at?->toIso8601String(),
            ] : null,
            'staff' => Staff::query()->orderBy('name')->get(['id', 'name', 'google_calendar_id'])->map(fn (Staff $member): array => [
                'id' => $member->id,
                'name' => $member->name,
                'google_calendar_id' => $member->google_calendar_id,
            ])->all(),
            'oauth_configured' => filled(config('services.google.client_id'))
                && filled(config('services.google.client_secret'))
                && filled(config('services.google.redirect_uri')),
        ]);
    }

    public function connect(Request $request, GoogleCalendarService $calendar): RedirectResponse
    {
        $state = bin2hex(random_bytes(32));
        $verifier = rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $request->session()->put('google_calendar_oauth', [
            'state' => $state,
            'code_verifier' => $verifier,
            'tenant_id' => app(TenantContext::class)->id(),
            'user_id' => $request->user()->id,
            'created_at' => now()->timestamp,
        ]);

        return redirect()->away($calendar->authorizationUrl($state, $challenge));
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

        $verifier = $expected['code_verifier'] ?? null;
        if (! is_string($verifier) || $verifier === '') {
            abort(403, 'Google OAuth proof key is missing.');
        }

        try {
            $tokens = $calendar->exchangeAuthorizationCode($code, $verifier);
            $userInfo = Http::withToken($tokens['access_token'])->acceptJson()->timeout(10)
                ->get(config('services.google.userinfo_url'));
        } catch (Throwable $exception) {
            Log::warning('Google Calendar OAuth callback failed.', ['exception' => $exception::class]);
            return redirect()->route('business.calendar.index')->withErrors([
                'calendar' => 'Google Calendar could not be connected. Please try again.',
            ]);
        }

        if (! $userInfo->successful() || ! is_string($userInfo->json('email')) || $userInfo->json('verified_email') !== true) {
            return redirect()->route('business.calendar.index')->withErrors([
                'calendar' => 'Google account identity could not be verified.',
            ]);
        }

        $grantedScopes = preg_split('/\\s+/', (string) ($tokens['scope'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $requiredScopes = [
            'https://www.googleapis.com/auth/calendar.events',
            'https://www.googleapis.com/auth/calendar.freebusy',
            'https://www.googleapis.com/auth/calendar.calendarlist.readonly',
        ];
        if (array_diff($requiredScopes, $grantedScopes) !== []) {
            return redirect()->route('business.calendar.index')->withErrors([
                'calendar' => 'Google did not grant all required Calendar permissions. Reconnect and approve the requested access.',
            ]);
        }

        $connection = GoogleCalendarConnection::query()->first();
        // A revoked refresh token must never be silently reused during reauthorization.
        // Google may omit refresh_token when consent is not newly granted; in that case
        // keep the connection in reauthorization_required instead of reactivating it.
        $sameAccountRefreshToken = $connection
            && $connection->status !== 'reauthorization_required'
            && $connection->google_account_email === $userInfo->json('email')
                ? $connection->refresh_token
                : null;
        $refreshToken = $tokens['refresh_token'] ?? $sameAccountRefreshToken;
        if (! is_string($refreshToken) || $refreshToken === '') {
            return redirect()->route('business.calendar.index')->withErrors([
                'calendar' => 'Google did not provide offline access. Disconnect the old authorization and reconnect with consent.',
            ]);
        }
        $connection ??= new GoogleCalendarConnection();
        $connection->fill([
            'connected_by_user_id' => $request->user()->id,
            'google_account_email' => $userInfo->json('email'),
            'access_token' => $tokens['access_token'],
            'refresh_token' => $refreshToken,
            'token_expires_at' => now()->addSeconds(max(60, (int) ($tokens['expires_in'] ?? 3600))),
            'scope' => $tokens['scope'] ?? null,
            'default_calendar_id' => $connection->default_calendar_id ?: 'primary',
            'status' => 'connected',
            'connected_at' => now(),
        ])->save();

        return redirect()->route('business.calendar.index')->with('success', 'Google Calendar connected successfully.');
    }


    public function calendars(GoogleCalendarService $calendar): JsonResponse
    {
        return response()->json(['calendars' => $calendar->listCalendars()]);
    }

    public function updateDefaultCalendar(Request $request, GoogleCalendarService $calendar): RedirectResponse
    {
        $validated = $request->validate(
            ['calendar_id' => ['required', 'string', 'max:255']],
            [
                'calendar_id.required' => 'Choose a default Google Calendar.',
                'calendar_id.string' => 'The selected calendar is invalid.',
                'calendar_id.max' => 'The selected calendar identifier is too long.',
            ],
        );
        $this->assertCalendarExists($calendar, $validated['calendar_id']);

        $connection = GoogleCalendarConnection::query()->firstOrFail();
        $connection->forceFill(['default_calendar_id' => $validated['calendar_id']])->save();

        return back()->with('success', 'Default Google Calendar updated.');
    }

    public function updateStaffCalendar(Request $request, string $staffId, GoogleCalendarService $calendar): RedirectResponse
    {
        $validated = $request->validate(
            ['calendar_id' => ['nullable', 'string', 'max:255']],
            [
                'calendar_id.string' => 'The selected staff calendar is invalid.',
                'calendar_id.max' => 'The selected calendar identifier is too long.',
            ],
        );
        $staff = Staff::query()->findOrFail($staffId);

        if (is_string($validated['calendar_id'] ?? null) && $validated['calendar_id'] !== '') {
            $this->assertCalendarExists($calendar, $validated['calendar_id']);
        }

        $staff->forceFill(['google_calendar_id' => $validated['calendar_id'] ?: null])->save();

        return back()->with('success', 'Staff calendar mapping updated.');
    }

    private function assertCalendarExists(GoogleCalendarService $calendar, string $calendarId): void
    {
        $exists = collect($calendar->listCalendars())->contains(
            static fn (array $item): bool => $item['id'] === $calendarId
                && in_array($item['access_role'] ?? null, ['owner', 'writer'], true),
        );

        if (! $exists) {
            throw ValidationException::withMessages([
                'calendar_id' => 'Choose a writable calendar from the connected Google account.',
            ]);
        }
    }

    public function disconnect(GoogleCalendarService $calendar): RedirectResponse
    {
        $connection = GoogleCalendarConnection::query()->first();
        $revoked = true;
        if ($connection) {
            $revoked = $calendar->revokeConnection($connection);
            $connection->forceFill([
                'connected_by_user_id' => null,
                'access_token' => null,
                'refresh_token' => null,
                'token_expires_at' => null,
                'status' => 'disconnected',
                'connected_at' => null,
            ])->save();
        }

        return back()->with('success', $revoked
            ? 'Google Calendar disconnected and the token revocation request succeeded.'
            : 'Google Calendar disconnected locally. Google could not confirm token revocation; review access in your Google Account security settings.');
    }
}
