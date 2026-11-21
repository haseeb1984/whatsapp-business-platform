<?php

namespace App\Jobs;

use App\Enums\MessageActor;
use App\Models\Appointment;
use App\Models\AppointmentReminder;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Messaging\WhatsAppMessageService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProcessAppointmentReminder implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $reminderId,
    ) {
    }

    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(WhatsAppMessageService $messenger): void
    {
        TenantContext::runForTenant($this->tenantId, function () use ($messenger): void {
            $context = DB::transaction(function (): ?array {
                $reminderSnapshot = AppointmentReminder::query()->find($this->reminderId);
                if (! $reminderSnapshot) {
                    return null;
                }

                $appointment = Appointment::query()->lockForUpdate()->find($reminderSnapshot->appointment_id);
                $reminder = AppointmentReminder::query()->lockForUpdate()->find($this->reminderId);
                if (! $appointment || ! $reminder
                    || ! in_array($reminder->status, ['pending', 'queued', 'processing'], true)) {
                    return null;
                }

                if ($appointment->status !== 'confirmed') {
                    $reminder->forceFill(['status' => 'cancelled', 'last_error' => 'appointment_not_confirmed'])->save();

                    return null;
                }

                if ($reminder->scheduled_at->isFuture()) {
                    return null;
                }

                if ($reminder->status === 'processing' && $this->attempts() <= 1
                    && $reminder->updated_at?->greaterThan(now()->subMinutes(10))) {
                    return null;
                }

                $reminder->forceFill([
                    'status' => 'processing',
                    'queued_at' => null,
                    'attempts' => $reminder->attempts + 1,
                    'last_error' => null,
                ])->save();

                return ['appointment' => $appointment, 'reminder' => $reminder->refresh()];
            });

            if (! $context) {
                return;
            }

            $appointment = $context['appointment'];
            $reminder = $context['reminder'];
            $conversation = Conversation::query()
                ->where('customer_id', $appointment->customer_id)
                ->where('status', 'open')
                ->orderByDesc('last_message_at')
                ->first();

            if (! $conversation) {
                $this->finish($reminder->id, 'blocked_template_required', 'no_open_customer_conversation');
                return;
            }

            if ($conversation->automation_state !== 'AUTO_ACTIVE') {
                $this->finish($reminder->id, 'blocked_handover', 'conversation_is_under_human_control');
                return;
            }

            $lastInbound = Message::query()
                ->where('conversation_id', $conversation->id)
                ->where('direction', 'inbound')
                ->orderByDesc('sent_at')
                ->orderByDesc('created_at')
                ->first();

            if (! $lastInbound?->sent_at || $lastInbound->sent_at->lessThan(now()->subHours(24))) {
                $this->finish($reminder->id, 'blocked_template_required', 'customer_service_window_closed');
                return;
            }

            $timezone = app(TenantContext::class)->tenant()->timezone ?: 'UTC';
            $localStart = $appointment->starts_at->setTimezone($timezone)->format('D, d M Y H:i');
            $body = 'Reminder: your '.$appointment->service_name.' appointment is scheduled for '.$localStart.'. Reply here if you need help.';

            try {
                $message = $messenger->sendText(
                    $conversation,
                    $body,
                    MessageActor::Automation,
                    null,
                    null,
                    $reminder->id,
                );

                $status = match ($message->delivery_status) {
                    'sent', 'delivered', 'read' => 'sent',
                    'unknown' => 'unknown',
                    default => 'failed',
                };

                $this->finish(
                    $reminder->id,
                    $status,
                    $status === 'failed' ? 'whatsapp_send_failed' : null,
                    $message->provider_message_id,
                    $message->sent_at,
                );
            } catch (ValidationException $exception) {
                $this->finish($reminder->id, 'blocked_handover', 'whatsapp_send_rejected');
            } catch (Throwable $exception) {
                $this->releaseReminderForRetry($reminder->id, $exception);
                throw $exception;
            }
        });
    }

    public function failed(Throwable $exception): void
    {
        TenantContext::runForTenant($this->tenantId, function () use ($exception): void {
            AppointmentReminder::query()
                ->whereKey($this->reminderId)
                ->whereIn('status', ['queued', 'processing'])
                ->update([
                    'status' => 'failed',
                    'last_error' => 'queue_attempts_exhausted',
                ]);
        });
    }

    private function finish(
        string $reminderId,
        string $status,
        ?string $error = null,
        ?string $providerMessageId = null,
        mixed $sentAt = null,
    ): void {
        AppointmentReminder::query()
            ->whereKey($reminderId)
            ->where('status', 'processing')
            ->update([
                'status' => $status,
                'last_error' => $error,
                'provider_message_id' => $providerMessageId,
                'sent_at' => $sentAt,
            ]);
    }

    private function releaseReminderForRetry(string $reminderId, Throwable $exception): void
    {
        AppointmentReminder::query()
            ->whereKey($reminderId)
            ->where('status', 'processing')
            ->update([
                'status' => 'queued',
                'queued_at' => now(),
                'last_error' => 'transient_processing_error',
            ]);
    }
}
