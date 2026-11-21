<?php

namespace App\Services\Meta;

use App\Models\WhatsAppConnection;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class MetaWhatsAppGraphApi
{
    public function verifyPhoneNumber(WhatsAppConnection $connection): array
    {
        $version = config('services.meta.graph_api_version');

        if (! is_string($version) || ! preg_match('/^v\d+\.\d+$/', $version)) {
            throw ValidationException::withMessages([
                'connection' => 'Configure a valid META_GRAPH_API_VERSION before verifying this connection.',
            ]);
        }

        $response = Http::withToken($connection->access_token)
            ->acceptJson()
            ->timeout(10)
            ->get("https://graph.facebook.com/{$version}/{$connection->phone_number_id}", [
                'fields' => 'id,display_phone_number,verified_name',
            ]);

        if (! $response->successful()) {
            throw ValidationException::withMessages([
                'connection' => 'Meta could not verify this phone number. Check the token, permissions and Phone Number ID.',
            ]);
        }

        $data = $response->json();
        if (! is_array($data) || ($data['id'] ?? null) !== $connection->phone_number_id) {
            throw ValidationException::withMessages([
                'connection' => 'Meta returned a different phone number ID. The connection remains pending.',
            ]);
        }

        $wabaResponse = Http::withToken($connection->access_token)
            ->acceptJson()
            ->timeout(10)
            ->get("https://graph.facebook.com/{$version}/{$connection->waba_id}/phone_numbers", [
                'fields' => 'id',
                'limit' => 100,
            ]);

        if (! $wabaResponse->successful()) {
            throw ValidationException::withMessages([
                'connection' => 'Meta could not confirm access to the configured WhatsApp Business Account.',
            ]);
        }

        $phoneNumbers = $wabaResponse->json('data');
        $belongsToWaba = is_array($phoneNumbers) && collect($phoneNumbers)->contains(
            fn ($phoneNumber): bool => is_array($phoneNumber) && ($phoneNumber['id'] ?? null) === $connection->phone_number_id,
        );

        if (! $belongsToWaba) {
            throw ValidationException::withMessages([
                'connection' => 'Meta confirmed the phone number, but it is not listed under the configured WhatsApp Business Account.',
            ]);
        }

        return $data;
    }
}
