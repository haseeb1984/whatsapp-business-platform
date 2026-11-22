<?php

namespace App\Jobs;

use App\Enums\MessageActor;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\AI\BusinessIntentRouter;
use App\Services\AI\FaqAssistantResult;
use App\Services\AI\GroundedFaqAssistant;
use App\Services\Messaging\WhatsAppMessageService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class GenerateAutomatedFaqReply implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $conversationId,
        public readonly string $inboundMessageId,
    ) {
    }

    public function backoff(): array
    {
        return [10, 30];
    }

    public function handle(GroundedFaqAssistant $assistant, BusinessIntentRouter $intents, WhatsAppMessageService $messenger): void
    {
        TenantContext::runForTenant($this->tenantId, function () use ($assistant, $intents, $messenger): void {
            $context = DB::transaction(function (): ?array {
                $conversation = Conversation::query()->lockForUpdate()->find($this->conversationId);
                if (! $conversation || $conversation->status !== 'open' || $conversation->automation_state !== 'AUTO_ACTIVE') {
                    return null;
                }

                $inbound = Message::query()
                    ->where('conversation_id', $conversation->id)
                    ->where('direction', 'inbound')
                    ->whereKey($this->inboundMessageId)
                    ->first();

                if (! $inbound || $inbound->message_type !== 'text' || ! is_string($inbound->body) || trim($inbound->body) === '') {
                    return null;
                }

                $latestInbound = Message::query()
                    ->where('conversation_id', $conversation->id)
                    ->where('direction', 'inbound')
                    ->orderByDesc('created_at')
                    ->first();

                if (! $latestInbound || $latestInbound->id !== $inbound->id) {
                    return null;
                }

                return [
                    'conversation' => $conversation,
                    'inbound' => $inbound,
                    'locale' => app(TenantContext::class)->tenant()->locale ?: 'en',
                ];
            });

            if (! $context) {
                return;
            }

            $intent = $intents->route($context['inbound']->body, $context['locale']);
            $result = match ($intent->status) {
                'answered' => new FaqAssistantResult('answered', $intent->answer, [], 'business_fact:'.$intent->intent),
                'handoff' => new FaqAssistantResult('handoff', null, [], 'business_fact_unavailable:'.$intent->intent),
                default => $assistant->answer($context['inbound']->body, $context['locale']),
            };

            if ($result->status !== 'answered' || ! is_string($result->answer) || trim($result->answer) === '' || mb_strlen($result->answer) > 4096) {
                DB::transaction(function () use ($result): void {
                    $conversation = Conversation::query()->lockForUpdate()->find($this->conversationId);
                    if (! $conversation || $conversation->status !== 'open' || $conversation->automation_state !== 'AUTO_ACTIVE') {
                        return;
                    }

                    $latestInbound = Message::query()
                        ->where('conversation_id', $conversation->id)
                        ->where('direction', 'inbound')
                        ->orderByDesc('created_at')
                        ->first();

                    if ($latestInbound?->id === $this->inboundMessageId) {
                        $conversation->forceFill([
                            'automation_state' => 'WAITING_FOR_STAFF',
                            'handover_requested_at' => now(),
                            'handover_reason' => str_starts_with($result->source, 'business_fact_unavailable:')
                                ? 'Requested business information is not configured: '.substr($result->source, strlen('business_fact_unavailable:'))
                                : 'No sufficiently relevant approved FAQ answer was available.',
                        ])->save();
                    }
                });

                return;
            }

            $messenger->sendText(
                $context['conversation'],
                $result->answer,
                MessageActor::Automation,
                $this->inboundMessageId,
            );
        });
    }
}
