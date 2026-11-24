<?php

namespace Tests\Feature;

use App\Contracts\AiProviderInterface;
use App\Models\BookingPolicy;
use App\Models\BusinessHour;
use App\Models\Service;
use App\Models\Tenant;
use App\Services\AI\BusinessToolAssistant;
use App\Services\AI\BusinessToolRegistry;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Carbon\CarbonImmutable;
use Mockery;
use Tests\TestCase;

class BusinessToolAssistantTest extends TestCase
{
    use RefreshDatabase;

    public function test_ai_can_only_select_allowlisted_read_only_service_search_and_results_are_tenant_scoped(): void
    {
        $tenantA = $this->tenant('business-tools-a');
        $tenantB = $this->tenant('business-tools-b');

        TenantContext::runForTenant($tenantA->id, function (): void {
            Service::query()->create([
                'name' => 'Balayage',
                'price_amount' => '4500.00',
                'currency' => 'PKR',
                'duration_minutes' => 120,
                'active' => true,
            ]);
            Service::query()->create([
                'name' => 'Balayage archived',
                'price_amount' => '100.00',
                'currency' => 'PKR',
                'duration_minutes' => 30,
                'active' => false,
            ]);
        });
        TenantContext::runForTenant($tenantB->id, fn () => Service::query()->create([
            'name' => 'Balayage private tenant service',
            'price_amount' => '9999.00',
            'currency' => 'PKR',
            'duration_minutes' => 180,
            'active' => true,
        ]));

        $assistant = $this->assistant([
            'decision' => 'tool',
            'tool' => 'search_services',
            'arguments' => ['query' => 'BALAYAGE'],
        ]);

        $result = TenantContext::runForTenant($tenantA->id, fn () => $assistant->answer(
            'Do you offer balayage?',
            'en',
        ));

        $this->assertSame('answered', $result->status);
        $this->assertSame('service_search', $result->intent);
        $this->assertStringContainsString('Balayage — 4500.00 PKR (120 minutes)', $result->answer);
        $this->assertStringNotContainsString('archived', $result->answer);
        $this->assertStringNotContainsString('private tenant', $result->answer);
    }

    public function test_unknown_tool_and_extra_arguments_are_rejected_without_execution(): void
    {
        $tenant = $this->tenant('business-tools-invalid');

        foreach ([
            ['decision' => 'tool', 'tool' => 'delete_customer', 'arguments' => []],
            ['decision' => 'tool', 'tool' => 'list_services', 'arguments' => ['tenant_id' => 'foreign-tenant']],
            ['decision' => 'tool', 'tool' => 'search_services', 'arguments' => ['query' => 'hair', 'limit' => 1000]],
            ['decision' => 'tool', 'tool' => 'get_business_hours', 'arguments' => ['period' => 'all']],
            ['decision' => 'tool', 'tool' => 'get_booking_policy', 'arguments' => ['tenant_id' => 'another-tenant']],
            ['decision' => 'tool', 'tool' => 'list_available_slots', 'arguments' => ['service_name' => 'Hair styling', 'date' => '2026-11-23', 'tenant_id' => 'another-tenant']],
        ] as $decision) {
            $assistant = $this->assistant($decision);
            $result = TenantContext::runForTenant($tenant->id, fn () => $assistant->answer(
                'Show me business information',
                'en',
            ));

            $this->assertSame('not_handled', $result->status);
            $this->assertNull($result->answer);
        }
    }

    public function test_business_hours_tool_uses_only_configured_tenant_hours(): void
    {
        $tenant = $this->tenant('business-tools-hours');
        TenantContext::runForTenant($tenant->id, fn () => BusinessHour::query()->create([
            'weekday' => 0,
            'is_closed' => false,
            'opens_at' => '10:00',
            'closes_at' => '18:00',
        ]));

        $assistant = $this->assistant([
            'decision' => 'tool',
            'tool' => 'get_business_hours',
            'arguments' => ['period' => 'week'],
        ]);

        $result = TenantContext::runForTenant($tenant->id, fn () => $assistant->answer(
            'When are you open?',
            'en',
        ));

        $this->assertSame('answered', $result->status);
        $this->assertStringContainsString('Monday: 10:00–18:00', $result->answer);
    }


    public function test_booking_policy_tool_returns_only_configured_read_only_booking_rules(): void
    {
        $tenant = $this->tenant('business-tools-booking-policy');
        TenantContext::runForTenant($tenant->id, fn () => BookingPolicy::query()->create([
            'minimum_notice_minutes' => 90,
            'booking_horizon_days' => 45,
            'slot_interval_minutes' => 30,
            'hold_minutes' => 12,
        ]));

        $assistant = $this->assistant([
            'decision' => 'tool',
            'tool' => 'get_booking_policy',
            'arguments' => [],
        ]);

        $result = TenantContext::runForTenant($tenant->id, fn () => $assistant->answer(
            'How far in advance can I book?',
            'en',
        ));

        $this->assertSame('answered', $result->status);
        $this->assertSame('booking_policy', $result->intent);
        $this->assertSame(
            'Booking policy: please book at least 90 minutes ahead. Appointments can be booked up to 45 days in advance, with slots offered every 30 minutes.',
            $result->answer,
        );
    }


    public function test_availability_tool_returns_read_only_local_slots_from_the_current_tenant_schedule(): void
    {
        $tenant = $this->tenant('business-tools-availability');
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-22 06:00:00 UTC'));

        TenantContext::runForTenant($tenant->id, function (): void {
            $service = Service::query()->create([
                'name' => 'Hair styling',
                'price_amount' => '4500.00',
                'currency' => 'PKR',
                'duration_minutes' => 60,
                'buffer_before_minutes' => 15,
                'buffer_after_minutes' => 15,
                'active' => true,
            ]);
            $staff = Staff::query()->create(['name' => 'Ayesha', 'active' => true]);
            StaffService::query()->create(['staff_id' => $staff->id, 'service_id' => $service->id]);
            BusinessHour::query()->create([
                'weekday' => 0,
                'is_closed' => false,
                'opens_at' => '09:00',
                'closes_at' => '17:00',
            ]);
            StaffWorkingHour::query()->create([
                'staff_id' => $staff->id,
                'weekday' => 0,
                'is_closed' => false,
                'opens_at' => '10:00',
                'closes_at' => '16:00',
            ]);
        });

        $assistant = $this->assistant([
            'decision' => 'tool',
            'tool' => 'list_available_slots',
            'arguments' => [
                'service_name' => 'Hair styling',
                'date' => '2026-11-23',
            ],
        ]);

        $result = TenantContext::runForTenant($tenant->id, fn () => $assistant->answer(
            'What times are available for Hair styling on 2026-11-23?',
            'en',
        ));

        CarbonImmutable::setTestNow();
        $this->assertSame('answered', $result->status);
        $this->assertSame('available_slots', $result->intent);
        $this->assertStringContainsString('10:15 — Ayesha', $result->answer);
        $this->assertStringContainsString('Asia/Karachi', $result->answer);
        $this->assertStringContainsString('Google Calendar is not connected', $result->answer);
    }

    public function test_malformed_provider_output_fails_closed(): void
    {
        $tenant = $this->tenant('business-tools-malformed');
        $assistant = $this->assistant([
            'decision' => 'tool',
            'tool' => 'list_services',
            'arguments' => [],
            'execute_arbitrary_code' => true,
        ]);

        $result = TenantContext::runForTenant($tenant->id, fn () => $assistant->answer(
            'List services',
            'en',
        ));

        $this->assertSame('not_handled', $result->status);
        $this->assertNull($result->answer);
    }

    private function assistant(array $decision): BusinessToolAssistant
    {
        $provider = Mockery::mock(AiProviderInterface::class);
        $provider->shouldReceive('generate')
            ->once()
            ->andReturn(json_encode($decision, JSON_THROW_ON_ERROR));

        return new BusinessToolAssistant($provider, app(BusinessToolRegistry::class));
    }

    private function tenant(string $slug): Tenant
    {
        return Tenant::query()->create([
            'name' => 'Business Tool Workspace',
            'slug' => $slug,
            'timezone' => 'Asia/Karachi',
        ]);
    }
}
