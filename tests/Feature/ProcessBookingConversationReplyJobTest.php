<?php

namespace Tests\Feature;

use App\Jobs\ProcessBookingConversationReply;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Tenant;
use App\Services\Booking\AppointmentCalendarOrchestrator;
use App\Services\Booking\BookingConversationHandler;
use App\Services\Messaging\WhatsAppMessageService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ProcessBookingConversationReplyJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_booking_reply_job_cannot_read_another_tenants_conversation(): void
    {
        $owner = Tenant::query()->create(['name' => 'Booking Owner', 'slug' => 'booking-owner']);
        $other = Tenant::query()->create(['name' => 'Other Workspace', 'slug' => 'booking-other']);

        [$conversation, $inbound] = TenantContext::runForTenant($owner->id, function (): array {
            $customer = Customer::query()->create(['whatsapp_wa_id' => '923001112233']);
            $conversation = Conversation::query()->create([
                'customer_id' => $customer->id,
                'status' => 'open',
                'automation_state' => 'AUTO_ACTIVE',
            ]);
            $inbound = Message::query()->create([
                'conversation_id' => $conversation->id,
                'direction' => 'inbound',
                'message_type' => 'text',
                'body' => 'I want to book an appointment',
                'provider_message_id' => 'wamid.booking-isolation',
                'delivery_status' => 'received',
                'sent_at' => now(),
            ]);

            return [$conversation, $inbound];
        });

        $booking = Mockery::mock(BookingConversationHandler::class);
        $calendar = Mockery::mock(AppointmentCalendarOrchestrator::class);
        $messenger = Mockery::mock(WhatsAppMessageService::class);
        $booking->shouldNotReceive('handle');
        $calendar->shouldNotReceive('confirm');
        $messenger->shouldNotReceive('sendText');

        (new ProcessBookingConversationReply($other->id, $conversation->id, $inbound->id))
            ->handle($booking, $calendar, $messenger);

        $this->assertDatabaseCount('messages', 1);
        $this->assertFalse(app()->bound(TenantContext::class), 'A completed booking job must not leak tenant context.');
    }
}
