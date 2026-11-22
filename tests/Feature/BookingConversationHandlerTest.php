<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\ConversationBookingSession;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Booking\BookingConversationHandler;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingConversationHandlerTest extends TestCase
{
    use RefreshDatabase;

    public function test_booking_intent_starts_a_tenant_scoped_service_selection_session(): void
    {
        [$tenant, $conversation] = $this->conversation('booking-start');

        $reply = TenantContext::runForTenant($tenant->id, fn () => app(BookingConversationHandler::class)->handle(
            $conversation,
            'I want to book an appointment',
            'wamid.booking-start',
        ));

        $this->assertStringContainsString('which service would you like to book', strtolower($reply));
        $this->assertStringContainsString('Consultation', $reply);

        $session = TenantContext::runForTenant($tenant->id, fn () => ConversationBookingSession::query()->firstOrFail());
        $this->assertSame('awaiting_service', $session->state);
        $this->assertSame([$this->serviceId($tenant)], $session->offered_services);
    }

    public function test_service_selection_moves_booking_conversation_to_date_selection(): void
    {
        [$tenant, $conversation] = $this->conversation('booking-service');

        TenantContext::runForTenant($tenant->id, fn () => app(BookingConversationHandler::class)->handle(
            $conversation,
            'book',
            'wamid.booking-service-start',
        ));

        $reply = TenantContext::runForTenant($tenant->id, fn () => app(BookingConversationHandler::class)->handle(
            $conversation,
            '1',
            'wamid.booking-service-choice',
        ));

        $this->assertStringContainsString('What date would you prefer', $reply);

        $session = TenantContext::runForTenant($tenant->id, fn () => ConversationBookingSession::query()->firstOrFail());
        $this->assertSame('awaiting_date', $session->state);
        $this->assertSame($this->serviceId($tenant), $session->service_id);
    }

    public function test_cancelled_booking_session_can_be_restarted_with_a_new_booking_intent(): void
    {
        [$tenant, $conversation] = $this->conversation('booking-restart');

        $reply = TenantContext::runForTenant($tenant->id, function () use ($conversation): ?string {
            $handler = app(BookingConversationHandler::class);
            $handler->handle($conversation, 'book', 'wamid.booking-restart-start');
            $handler->handle($conversation, 'cancel', 'wamid.booking-restart-cancel');

            return $handler->handle($conversation, 'book', 'wamid.booking-restart-again');
        });

        $this->assertStringContainsString('which service would you like to book', strtolower($reply));

        $session = TenantContext::runForTenant($tenant->id, fn () => ConversationBookingSession::query()->firstOrFail());
        $this->assertSame('awaiting_service', $session->state);
    }


    public function test_natural_language_appointment_request_starts_booking_session(): void
    {
        [$tenant, $conversation] = $this->conversation('booking-natural-language');

        $reply = TenantContext::runForTenant($tenant->id, fn () => app(BookingConversationHandler::class)->handle(
            $conversation,
            'I need an appointment',
            'wamid.booking-natural-language',
        ));

        $this->assertStringContainsString('which service would you like to book', strtolower($reply));
        $session = TenantContext::runForTenant($tenant->id, fn () => ConversationBookingSession::query()->firstOrFail());
        $this->assertSame('awaiting_service', $session->state);
    }


    public function test_service_name_in_a_natural_request_starts_booking_flow(): void
    {
        [$tenant, $conversation] = $this->conversation('booking-service-intent');

        $reply = TenantContext::runForTenant($tenant->id, fn () => app(BookingConversationHandler::class)->handle(
            $conversation,
            'I want a consultation',
            'wamid.booking-service-intent',
        ));

        $this->assertStringContainsString('which service would you like to book', strtolower($reply));
        $session = TenantContext::runForTenant($tenant->id, fn () => ConversationBookingSession::query()->firstOrFail());
        $this->assertSame('awaiting_service', $session->state);
    }


    public function test_service_price_question_does_not_start_a_booking_session(): void
    {
        [$tenant, $conversation] = $this->conversation('booking-price-question');

        $reply = TenantContext::runForTenant($tenant->id, fn () => app(BookingConversationHandler::class)->handle(
            $conversation,
            'I want to know the price of a consultation',
            'wamid.booking-price-question',
        ));

        $this->assertNull($reply);
        TenantContext::runForTenant($tenant->id, fn () => $this->assertDatabaseCount('conversation_booking_sessions', 0));
    }

    private function conversation(string $slug): array
    {
        $user = User::query()->create([
            'name' => 'Booking Test Admin',
            'email' => $slug.'@example.test',
            'password' => 'StrongPassword123',
        ]);
        $tenant = Tenant::query()->create([
            'name' => 'Booking Test Workspace',
            'slug' => $slug,
            'timezone' => 'Asia/Karachi',
        ]);
        $tenant->users()->attach($user->id, ['role' => 'business_admin', 'status' => 'active']);

        return TenantContext::runForTenant($tenant->id, function () use ($slug, $tenant): array {
            $service = Service::query()->create([
                'name' => 'Consultation',
                'price_amount' => '1500.00',
                'currency' => 'PKR',
                'duration_minutes' => 30,
                'buffer_before_minutes' => 0,
                'buffer_after_minutes' => 0,
                'active' => true,
            ]);
            $customer = Customer::query()->create(['whatsapp_wa_id' => '92300'.str_pad((string) random_int(1000000, 9999999), 7, '0', STR_PAD_LEFT)]);
            $conversation = Conversation::query()->create([
                'customer_id' => $customer->id,
                'status' => 'open',
                'automation_state' => 'AUTO_ACTIVE',
                'last_message_at' => now(),
            ]);

            return [$tenant, $conversation, $service->id];
        });
    }

    private function serviceId(Tenant $tenant): string
    {
        return TenantContext::runForTenant($tenant->id, fn () => Service::query()->value('id'));
    }
}
