<?php

namespace Tests\Feature;

use App\Models\BusinessHoliday;
use App\Models\BusinessHour;
use App\Models\BusinessProfile;
use App\Models\Service;
use App\Models\Tenant;
use App\Services\AI\BusinessIntentRouter;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessIntentRouterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-22 06:00:00 UTC'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_service_pricing_comes_from_active_tenant_services_without_ai_generation(): void
    {
        $tenant = $this->tenant('intent-pricing');
        TenantContext::runForTenant($tenant->id, function (): void {
            Service::query()->create([
                'name' => 'Consultation',
                'price_amount' => '1500.00',
                'currency' => 'PKR',
                'duration_minutes' => 30,
                'active' => true,
            ]);
            Service::query()->create([
                'name' => 'Premium Session',
                'price_amount' => '3500.00',
                'currency' => 'PKR',
                'duration_minutes' => 60,
                'active' => true,
            ]);
        });

        $result = TenantContext::runForTenant($tenant->id, fn () => app(BusinessIntentRouter::class)->route('How much do your services cost?', 'en'));

        $this->assertSame('answered', $result->status);
        $this->assertSame('service_pricing', $result->intent);
        $this->assertStringContainsString('Consultation — 1500.00 PKR (30 minutes)', $result->answer);
        $this->assertStringContainsString('Premium Session — 3500.00 PKR (60 minutes)', $result->answer);
    }

    public function test_today_hours_respect_business_local_timezone_and_holiday_override(): void
    {
        $tenant = $this->tenant('intent-hours');
        TenantContext::runForTenant($tenant->id, function (): void {
            BusinessHour::query()->create([
                'weekday' => 6,
                'is_closed' => false,
                'opens_at' => '09:00',
                'closes_at' => '17:00',
            ]);
            BusinessHoliday::query()->create([
                'date' => '2026-11-22',
                'name' => 'Public holiday',
                'is_closed' => true,
            ]);
        });

        $result = TenantContext::runForTenant($tenant->id, fn () => app(BusinessIntentRouter::class)->route('Are you open today?', 'en'));

        $this->assertSame('answered', $result->status);
        $this->assertSame('today_hours', $result->intent);
        $this->assertSame('We are closed today for Public holiday.', $result->answer);
    }

    public function test_contact_questions_return_only_configured_business_profile_fields(): void
    {
        $tenant = $this->tenant('intent-contact');
        TenantContext::runForTenant($tenant->id, fn () => BusinessProfile::query()->create([
            'display_name' => 'Primary Business',
            'phone' => '+923001112233',
            'address' => 'North Nazimabad, Karachi',
            'website' => 'https://example.test',
        ]));

        $result = TenantContext::runForTenant($tenant->id, fn () => app(BusinessIntentRouter::class)->route('Where are you located and how can I contact you?', 'en'));

        $this->assertSame('answered', $result->status);
        $this->assertStringContainsString('Address: North Nazimabad, Karachi', $result->answer);
        $this->assertStringContainsString('Phone: +923001112233', $result->answer);
        $this->assertStringNotContainsString('Email:', $result->answer);
    }

    public function test_missing_business_facts_and_unrecognized_questions_fall_back_to_faq(): void
    {
        $tenant = $this->tenant('intent-missing');
        $router = app(BusinessIntentRouter::class);

        $missing = TenantContext::runForTenant($tenant->id, fn () => $router->route('What are your opening hours?', 'en'));
        $other = TenantContext::runForTenant($tenant->id, fn () => $router->route('Can you explain your refund policy?', 'en'));

        $this->assertSame('not_handled', $missing->status);
        $this->assertSame('business_hours_not_configured', $missing->intent);
        $this->assertSame('not_handled', $other->status);
    }


    public function test_tomorrow_hours_use_the_next_business_local_weekday(): void
    {
        $tenant = $this->tenant('intent-tomorrow-hours');
        TenantContext::runForTenant($tenant->id, fn () => BusinessHour::query()->create([
            'weekday' => 0,
            'is_closed' => false,
            'opens_at' => '10:00',
            'closes_at' => '16:00',
        ]));

        $result = TenantContext::runForTenant($tenant->id, fn () => app(BusinessIntentRouter::class)->route('Are you open tomorrow?', 'en'));

        $this->assertSame('answered', $result->status);
        $this->assertSame('tomorrow_hours', $result->intent);
        $this->assertSame('Tomorrow’s hours are 10:00–16:00 Asia/Karachi.', $result->answer);
    }

    public function test_urdu_pricing_questions_return_only_configured_active_services(): void
    {
        $tenant = $this->tenant('intent-urdu-pricing');
        TenantContext::runForTenant($tenant->id, fn () => Service::query()->create([
            'name' => 'Consultation',
            'price_amount' => '1500.00',
            'currency' => 'PKR',
            'duration_minutes' => 30,
            'active' => true,
        ]));

        $result = TenantContext::runForTenant($tenant->id, fn () => app(BusinessIntentRouter::class)->route('آپ کی خدمات کی قیمت کتنی ہے؟', 'ur-PK'));

        $this->assertSame('answered', $result->status);
        $this->assertSame('service_pricing', $result->intent);
        $this->assertStringContainsString('ہماری موجودہ خدمات اور قیمتیں یہ ہیں:', $result->answer);
        $this->assertStringContainsString('Consultation — 1500.00 PKR', $result->answer);
    }

    public function test_roman_urdu_tomorrow_hours_are_resolved_in_business_timezone(): void
    {
        $tenant = $this->tenant('intent-roman-urdu-hours');
        TenantContext::runForTenant($tenant->id, fn () => BusinessHour::query()->create([
            'weekday' => 0,
            'is_closed' => false,
            'opens_at' => '10:00',
            'closes_at' => '16:00',
        ]));

        $result = TenantContext::runForTenant($tenant->id, fn () => app(BusinessIntentRouter::class)->route('Kal khulay hain?', 'ur-Latn-PK'));

        $this->assertSame('answered', $result->status);
        $this->assertSame('tomorrow_hours', $result->intent);
        $this->assertStringContainsString('Kal hamaray auqat 10:00 se 16:00 tak hain.', $result->answer);
    }

    public function test_roman_urdu_contact_questions_return_only_configured_fields(): void
    {
        $tenant = $this->tenant('intent-roman-urdu-contact');
        TenantContext::runForTenant($tenant->id, fn () => BusinessProfile::query()->create([
            'display_name' => 'Primary Business',
            'phone' => '+923001112233',
            'address' => 'North Nazimabad, Karachi',
        ]));

        $result = TenantContext::runForTenant($tenant->id, fn () => app(BusinessIntentRouter::class)->route('Aap ka pata kahan hai?', 'ur-Latn'));

        $this->assertSame('answered', $result->status);
        $this->assertStringContainsString('Pata: North Nazimabad, Karachi', $result->answer);
        $this->assertStringContainsString('Phone: +923001112233', $result->answer);
    }

    public function test_roman_urdu_price_and_service_variants_are_recognized(): void
    {
        $tenant = $this->tenant('intent-roman-urdu-variants');
        TenantContext::runForTenant($tenant->id, fn () => Service::query()->create([
            'name' => 'Hair Styling',
            'price_amount' => '2200.00',
            'currency' => 'PKR',
            'duration_minutes' => 45,
            'active' => true,
        ]));

        $pricing = TenantContext::runForTenant($tenant->id, fn () => app(BusinessIntentRouter::class)->route(
            'Ye service kitnay ki hai?',
            'ur-Latn-PK',
        ));
        $catalog = TenantContext::runForTenant($tenant->id, fn () => app(BusinessIntentRouter::class)->route(
            'Aap konsi services offer kartay hain?',
            'ur-Latn-PK',
        ));

        $this->assertSame('answered', $pricing->status);
        $this->assertSame('service_pricing', $pricing->intent);
        $this->assertStringContainsString('2200.00 PKR', $pricing->answer);
        $this->assertSame('answered', $catalog->status);
        $this->assertSame('service_catalog', $catalog->intent);
        $this->assertStringContainsString('Hair Styling', $catalog->answer);
    }

    public function test_urdu_and_roman_urdu_opening_time_variants_are_recognized(): void
    {
        $tenant = $this->tenant('intent-hours-language-variants');
        TenantContext::runForTenant($tenant->id, function (): void {
            BusinessHour::query()->create([
                'weekday' => 6,
                'is_closed' => false,
                'opens_at' => '09:30',
                'closes_at' => '18:30',
            ]);
        });

        $roman = TenantContext::runForTenant($tenant->id, fn () => app(BusinessIntentRouter::class)->route(
            'Aaj kis waqt khultay ho?',
            'ur-Latn-PK',
        ));
        $urdu = TenantContext::runForTenant($tenant->id, fn () => app(BusinessIntentRouter::class)->route(
            'آپ آج کس وقت کھلتے ہیں؟',
            'ur-PK',
        ));

        $this->assertSame('answered', $roman->status);
        $this->assertSame('today_hours', $roman->intent);
        $this->assertStringContainsString('09:30–18:30', $roman->answer);
        $this->assertSame('answered', $urdu->status);
        $this->assertSame('today_hours', $urdu->intent);
    }

    public function test_urdu_contact_number_question_returns_only_configured_phone(): void
    {
        $tenant = $this->tenant('intent-urdu-contact-number');
        TenantContext::runForTenant($tenant->id, fn () => BusinessProfile::query()->create([
            'display_name' => 'Primary Business',
            'phone' => '+923001112233',
        ]));

        $result = TenantContext::runForTenant($tenant->id, fn () => app(BusinessIntentRouter::class)->route(
            'آپ کا رابطہ نمبر کیا ہے؟',
            'ur-PK',
        ));

        $this->assertSame('answered', $result->status);
        $this->assertStringContainsString('فون: +923001112233', $result->answer);
        $this->assertStringNotContainsString('Email:', $result->answer);
    }

    private function tenant(string $slug): Tenant
    {
        return Tenant::query()->create([
            'name' => 'Intent Workspace',
            'slug' => $slug,
            'timezone' => 'Asia/Karachi',
        ]);
    }
}
