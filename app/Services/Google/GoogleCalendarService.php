<?php

namespace App\Services\Google;

use App\Exceptions\GoogleCalendarBusyException;
use App\Models\Appointment;
use App\Models\GoogleCalendarConnection;
use App\Models\Staff;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class GoogleCalendarService
{
    public const SCOPES = [
        'openid',
        'email',
        'https://www.googleapis.com/auth/calendar.events',
        'https://www.googleapis.com/auth/calendar.freebusy',
    ];

    public function authorizationUrl(string $state, string $codeChallenge): string
    {
        $this->assertConfigured();

        return (string) config('services.google.authorization_url').'?'.http_build_query([
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => config('services.google.redirect_uri'),
            'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchangeAuthorizationCode(string $code, string $codeVerifier): array
    {
        $this->assertConfigured();
        $response = Http::asForm()->acceptJson()->timeout(15)->post(config('services.google.token_url'), [
            'code' => $code,
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'redirect_uri' => config('services.google.redirect_uri'),
            'grant_type' => 'authorization_code',
            'code_verifier' => $codeVerifier,
        ]);

        return $this->validatedTokenResponse($response);
    }

    public function refreshAccessToken(GoogleCalendarConnection $connection): string
    {
        if (! $connection->refresh_token) {
            throw new RuntimeException('Google Calendar authorization must be reconnected.');
        }

        $response = Http::asForm()->acceptJson()->timeout(15)->post(config('services.google.token_url'), [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'refresh_token' => $connection->refresh_token,
            'grant_type' => 'refresh_token',
        ]);
        $data = $this->validatedTokenResponse($response);

        $connection->forceFill([
            'access_token' => $data['access_token'],
            'token_expires_at' => now()->addSeconds(max(60, (int) ($data['expires_in'] ?? 3600))),
            'scope' => $data['scope'] ?? $connection->scope,
            'status' => 'connected',
        ])->save();

        return $data['access_token'];
    }


    public function listCalendars(): array
    {
        $connection = $this->connectedConnection();
        $token = $this->accessToken($connection);
        $response = $this->apiRequest($connection, $token, 'get', '/users/me/calendarList?minAccessRole=writer');

        return array_values(array_map(static fn (array $calendar): array => [
            'id' => $calendar['id'],
            'summary' => $calendar['summary'] ?? $calendar['id'],
            'primary' => (bool) ($calendar['primary'] ?? false),
            'access_role' => $calendar['accessRole'] ?? 'reader',
        ], array_filter($response['items'] ?? [], static fn ($item): bool => is_array($item) && is_string($item['id'] ?? null))));
    }

    public function revoke(string $token): void
    {
        if ($token === '') {
            return;
        }

        Http::asForm()->acceptJson()->timeout(10)->post(config('services.google.revoke_url'), [
            'token' => $token,
        ]);
    }

    public function freeBusy(array $calendarIds, string $timeMin, string $timeMax): array
    {
        $connection = $this->connectedConnection();
        $token = $this->accessToken($connection);
        $response = $this->apiRequest($connection, $token, 'post', '/freeBusy', [
            'timeMin' => $timeMin,
            'timeMax' => $timeMax,
            'items' => array_values(array_unique($calendarIds)),
        ]);

        return $response['calendars'] ?? [];
    }


    public function assertAppointmentCalendarAvailable(Appointment $appointment): void
    {
        $connection = $this->connectedConnection();
        $staff = Staff::query()->findOrFail($appointment->staff_id);
        $calendarId = $staff->google_calendar_id ?: $connection->default_calendar_id;
        $busy = $this->freeBusy(
            [$calendarId],
            $appointment->reserved_starts_at->utc()->toIso8601String(),
            $appointment->reserved_ends_at->utc()->toIso8601String(),
        );

        foreach (($busy[$calendarId]['busy'] ?? []) as $period) {
            if (! is_string($period['start'] ?? null) || ! is_string($period['end'] ?? null)) {
                continue;
            }

            $busyStart = strtotime($period['start']);
            $busyEnd = strtotime($period['end']);
            if ($busyStart !== false && $busyEnd !== false
                && $busyStart < $appointment->reserved_ends_at->getTimestamp()
                && $busyEnd > $appointment->reserved_starts_at->getTimestamp()) {
                throw new GoogleCalendarBusyException('The selected staff calendar is busy during this appointment.');
            }
        }
    }

    public function createAppointmentEvent(Appointment $appointment): string
    {
        $connection = $this->connectedConnection();
        $token = $this->accessToken($connection);
        $staff = Staff::query()->findOrFail($appointment->staff_id);
        $calendarId = $staff->google_calendar_id ?: $connection->default_calendar_id;
        $eventId = substr(hash('sha256', 'appointment:'.$appointment->id), 0, 32);

        $payload = [
            'id' => $eventId,
            'summary' => $appointment->service_name,
            'description' => 'WhatsApp Business appointment '.$appointment->id,
            'start' => ['dateTime' => $appointment->starts_at->toIso8601String()],
            'end' => ['dateTime' => $appointment->ends_at->toIso8601String()],
            'extendedProperties' => ['private' => [
                'platformAppointmentId' => $appointment->id,
                'platformTenantId' => $appointment->tenant_id,
            ]],
        ];

        try {
            $response = $this->apiRequest($connection, $token, 'post', '/calendars/'.rawurlencode($calendarId).'/events', $payload);
            return $response['id'];
        } catch (RuntimeException $exception) {
            // A retry after an ambiguous response must look up the deterministic event ID before creating another.
            $existing = $this->getEvent($connection, $token, $calendarId, $eventId);
            if ($existing && ($existing['extendedProperties']['private']['platformAppointmentId'] ?? null) === $appointment->id) {
                return $existing['id'];
            }

            throw $exception;
        }
    }

    public function deleteAppointmentEvent(Appointment $appointment): void
    {
        if (! $appointment->external_event_id) {
            throw new RuntimeException('The appointment has no linked Google Calendar event.');
        }

        $connection = $this->connectedConnection();
        $token = $this->accessToken($connection);
        $staff = Staff::query()->findOrFail($appointment->staff_id);
        $calendarId = $staff->google_calendar_id ?: $connection->default_calendar_id;

        try {
            $this->apiRequest($connection, $token, 'delete', '/calendars/'.rawurlencode($calendarId).'/events/'.rawurlencode($appointment->external_event_id));
        } catch (RuntimeException $exception) {
            if (! str_contains($exception->getMessage(), 'HTTP 404')) {
                throw $exception;
            }
        }
    }

    private function getEvent(GoogleCalendarConnection $connection, string $token, string $calendarId, string $eventId): ?array
    {
        try {
            return $this->apiRequest($connection, $token, 'get', '/calendars/'.rawurlencode($calendarId).'/events/'.rawurlencode($eventId));
        } catch (RuntimeException $exception) {
            if (str_contains($exception->getMessage(), 'HTTP 404')) {
                return null;
            }

            throw $exception;
        }
    }

    private function connectedConnection(): GoogleCalendarConnection
    {
        $connection = GoogleCalendarConnection::query()->first();
        if (! $connection || $connection->status !== 'connected' || ! $connection->access_token) {
            throw ValidationException::withMessages(['calendar' => 'Connect Google Calendar before using calendar operations.']);
        }

        return $connection;
    }

    private function accessToken(GoogleCalendarConnection $connection): string
    {
        if (! $connection->token_expires_at || $connection->token_expires_at->lessThanOrEqualTo(now()->addMinute())) {
            return $this->refreshAccessToken($connection);
        }

        return $connection->access_token;
    }

    private function apiRequest(GoogleCalendarConnection $connection, string $token, string $method, string $path, ?array $payload = null): array
    {
        $url = rtrim((string) config('services.google.api_base_url'), '/').$path;
        $request = Http::withToken($token)->acceptJson()->timeout(20);
        $response = match ($method) {
            'get' => $request->get($url),
            'post' => $request->post($url, $payload ?? []),
            'delete' => $request->delete($url),
            default => throw new RuntimeException('Unsupported Google Calendar operation.'),
        };

        if ($response->status() === 401) {
            $token = $this->refreshAccessToken($connection);
            $request = Http::withToken($token)->acceptJson()->timeout(20);
            $response = match ($method) {
                'get' => $request->get($url),
                'post' => $request->post($url, $payload ?? []),
                'delete' => $request->delete($url),
                default => throw new RuntimeException('Unsupported Google Calendar operation.'),
            };
        }

        if (! $response->successful()) {
            throw new RuntimeException('Google Calendar API request failed with HTTP '.$response->status().'.');
        }

        return $response->json() ?? [];
    }

    private function validatedTokenResponse(Response $response): array
    {
        if (! $response->successful() || ! is_string($response->json('access_token'))) {
            throw new RuntimeException('Google OAuth token exchange failed with HTTP '.$response->status().'.');
        }

        return $response->json();
    }

    private function assertConfigured(): void
    {
        foreach (['client_id', 'client_secret', 'redirect_uri'] as $key) {
            if (! is_string(config('services.google.'.$key)) || config('services.google.'.$key) === '') {
                throw ValidationException::withMessages(['calendar' => 'Google Calendar OAuth credentials are not configured.']);
            }
        }
    }
}
