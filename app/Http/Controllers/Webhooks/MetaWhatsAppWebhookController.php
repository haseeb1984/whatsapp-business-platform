<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;
use Symfony\Component\HttpFoundation\Response;

class MetaWhatsAppWebhookController extends Controller
{
    private const MAX_PAYLOAD_BYTES = 1_048_576;

    public function verify(Request $request): Response
    {
        $mode = $request->query('hub_mode', $request->query('hub.mode'));
        $providedToken = $request->query('hub_verify_token', $request->query('hub.verify_token'));
        $challenge = $request->query('hub_challenge', $request->query('hub.challenge'));
        $expectedToken = config('services.meta.webhook_verify_token');

        if (
            $mode !== 'subscribe'
            || ! is_string($expectedToken)
            || $expectedToken === ''
            || ! is_string($providedToken)
            || ! hash_equals($expectedToken, $providedToken)
            || ! is_string($challenge)
        ) {
            return response('Forbidden', 403);
        }

        return response($challenge, 200)->header('Content-Type', 'text/plain');
    }

    public function receive(Request $request): Response
    {
        $rawBody = $request->getContent();
        if (strlen($rawBody) > self::MAX_PAYLOAD_BYTES) {
            return response('Payload too large', 413);
        }

        $appSecret = config('services.meta.app_secret');
        $signature = $request->header('X-Hub-Signature-256');

        if (! is_string($appSecret) || $appSecret === '' || ! is_string($signature)) {
            return response('Unauthorized', 401);
        }

        $expectedSignature = 'sha256='.hash_hmac('sha256', $rawBody, $appSecret);
        if (! hash_equals($expectedSignature, $signature)) {
            return response('Unauthorized', 401);
        }

        try {
            $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return response('Invalid JSON', 400);
        }

        if (
            ! is_array($payload)
            || ($payload['object'] ?? null) !== 'whatsapp_business_account'
            || ! is_array($payload['entry'] ?? null)
        ) {
            return response('Invalid webhook payload', 400);
        }

        DB::transaction(function () use ($payload): void {
            foreach ($payload['entry'] as $entry) {
                if (! is_array($entry) || ! is_array($entry['changes'] ?? null)) {
                    continue;
                }

                foreach ($entry['changes'] as $change) {
                    if (! is_array($change) || ! is_array($change['value'] ?? null)) {
                        continue;
                    }

                    $value = $change['value'];
                    $phoneNumberId = data_get($value, 'metadata.phone_number_id');
                    $phoneNumberId = is_string($phoneNumberId) ? $phoneNumberId : null;

                    $tenantId = $phoneNumberId
                        ? DB::table('whatsapp_connections')
                            ->where('phone_number_id', $phoneNumberId)
                            ->where('status', 'active')
                            ->value('tenant_id')
                        : null;

                    $field = is_string($change['field'] ?? null) ? $change['field'] : 'unknown';
                    $items = [];

                    foreach (($value['messages'] ?? []) as $message) {
                        if (is_array($message)) {
                            $items[] = ['type' => 'message.received', 'payload' => $message];
                        }
                    }

                    foreach (($value['statuses'] ?? []) as $status) {
                        if (is_array($status)) {
                            $items[] = ['type' => 'message.status', 'payload' => $status];
                        }
                    }

                    if ($items === []) {
                        $items[] = ['type' => $field, 'payload' => $value];
                    }

                    foreach ($items as $item) {
                        $serialized = json_encode([
                            'phone_number_id' => $phoneNumberId,
                            'field' => $field,
                            'type' => $item['type'],
                            'payload' => $item['payload'],
                        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

                        DB::table('webhook_events')->insertOrIgnore([
                            'id' => (string) Str::uuid(),
                            'tenant_id' => $tenantId,
                            'provider' => 'meta_whatsapp',
                            'provider_event_key' => hash('sha256', $serialized),
                            'phone_number_id' => $phoneNumberId,
                            'event_type' => $item['type'],
                            'payload' => Crypt::encryptString(json_encode($item['payload'], JSON_THROW_ON_ERROR)),
                            'status' => $tenantId ? 'pending' : 'unmatched',
                            'attempts' => 0,
                            'received_at' => now(),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            }
        });

        return response('EVENT_RECEIVED', 200)->header('Content-Type', 'text/plain');
    }
}
