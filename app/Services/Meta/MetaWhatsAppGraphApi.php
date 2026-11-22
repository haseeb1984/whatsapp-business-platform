<?php

namespace App\Services\Meta;

use App\Models\WhatsAppConnection;
use App\Exceptions\MetaWhatsAppApiException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class MetaWhatsAppGraphApi
{
    public function verifyPhoneNumber(WhatsAppConnection $connection): array
    {
        $version = $this->configuredVersion();

        if ($version === null) {
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

    public function listMessageTemplates(WhatsAppConnection $connection): array
    {
        $version = $this->configuredVersion();
        if ($version === null) {
            throw ValidationException::withMessages([
                'templates' => 'Configure a valid META_GRAPH_API_VERSION before synchronizing templates.',
            ]);
        }

        $response = Http::withToken($connection->access_token)
            ->acceptJson()
            ->timeout(15)
            ->get("https://graph.facebook.com/{$version}/{$connection->waba_id}/message_templates", [
                'fields' => 'id,name,status,category,language,components',
                'limit' => 100,
            ]);

        if (! $response->successful()) {
            throw ValidationException::withMessages([
                'templates' => 'Meta could not retrieve message templates. Check WhatsApp Business Account access and the whatsapp_business_management permission.',
            ]);
        }

        $templates = $response->json('data');
        if (! is_array($templates)) {
            throw ValidationException::withMessages([
                'templates' => 'Meta returned an invalid message-template response.',
            ]);
        }

        return collect($templates)->filter(fn ($template): bool =>
            is_array($template)
            && is_string($template['id'] ?? null)
            && is_string($template['name'] ?? null)
            && is_string($template['language'] ?? null)
            && is_string($template['status'] ?? null)
        )->values()->all();
    }

    public function sendText(WhatsAppConnection $connection, string $recipient, string $body): string
    {
        $version = $this->configuredVersion();
        if ($version === null) {
            throw new MetaWhatsAppApiException('Configure META_GRAPH_API_VERSION before sending messages.');
        }
        if (! ctype_digit($recipient)) {
            throw new MetaWhatsAppApiException('The WhatsApp recipient ID is invalid.');
        }

        $response = Http::withToken($connection->access_token)
            ->acceptJson()
            ->timeout(15)
            ->post("https://graph.facebook.com/{$version}/{$connection->phone_number_id}/messages", [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $recipient,
                'type' => 'text',
                'text' => [
                    'preview_url' => false,
                    'body' => $body,
                ],
            ]);

        if (! $response->successful()) {
            $status = $response->status();
            throw new MetaWhatsAppApiException(
                'Meta rejected the WhatsApp message request.',
                $status === 429 || $status >= 500,
            );
        }

        $messageId = $response->json('messages.0.id');
        if (! is_string($messageId) || $messageId === '') {
            throw new MetaWhatsAppApiException('Meta accepted the request without returning a message ID.', true);
        }

        return $messageId;
    }


    public function sendTemplate(WhatsAppConnection $connection, string $recipient, string $name, string $language, array $components): string
    {
        $version = $this->configuredVersion();
        if ($version === null) {
            throw new MetaWhatsAppApiException('Configure META_GRAPH_API_VERSION before sending messages.');
        }
        if (! ctype_digit($recipient)) {
            throw new MetaWhatsAppApiException('The WhatsApp recipient ID is invalid.');
        }

        $response = Http::withToken($connection->access_token)
            ->acceptJson()
            ->timeout(15)
            ->post("https://graph.facebook.com/{$version}/{$connection->phone_number_id}/messages", [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $recipient,
                'type' => 'template',
                'template' => [
                    'name' => $name,
                    'language' => ['code' => $language],
                    'components' => $components,
                ],
            ]);

        if (! $response->successful()) {
            $status = $response->status();
            throw new MetaWhatsAppApiException(
                'Meta rejected the WhatsApp template request.',
                $status === 429 || $status >= 500,
            );
        }

        $messageId = $response->json('messages.0.id');
        if (! is_string($messageId) || $messageId === '') {
            throw new MetaWhatsAppApiException('Meta accepted the template request without returning a message ID.', true);
        }

        return $messageId;
    }

    private function configuredVersion(): ?string
    {
        $version = config('services.meta.graph_api_version');

        return is_string($version) && preg_match('/^v\d+\.\d+$/', $version) ? $version : null;
    }
}
