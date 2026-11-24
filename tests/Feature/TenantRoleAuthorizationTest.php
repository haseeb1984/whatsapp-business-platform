<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireTenantRole;
use App\Http\Middleware\ResolveTenant;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TenantRoleAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', 'auth', ResolveTenant::class, RequireTenantRole::class.':business_admin,manager'])
            ->get('/_test/admin-only', fn () => response()->json(['role' => app(TenantContext::class)->role()]));
    }

    public function test_business_admin_can_access_role_protected_tenant_route(): void
    {
        [$user, $tenant] = $this->makeMembership('business_admin');

        $this->actingAs($user)
            ->withSession(['current_tenant_id' => $tenant->id])
            ->getJson('/_test/admin-only')
            ->assertOk()
            ->assertJsonPath('role', 'business_admin');
    }

    public function test_staff_cannot_access_admin_only_tenant_route(): void
    {
        [$user, $tenant] = $this->makeMembership('staff');

        $this->actingAs($user)
            ->withSession(['current_tenant_id' => $tenant->id])
            ->getJson('/_test/admin-only')
            ->assertForbidden();
    }

    public function test_staff_is_forbidden_from_real_business_configuration_routes(): void
    {
        [$user, $tenant] = $this->makeMembership('staff');

        $this->actingAs($user)->withSession(['current_tenant_id' => $tenant->id]);

        $this->get('/business/profile')->assertForbidden();
        $this->put('/business/profile', ['name' => 'Attempted takeover'])->assertForbidden();
        $this->post('/business/faqs', [
            'question' => 'Can I book?',
            'answer' => 'Yes',
        ])->assertForbidden();
        $this->post('/business/whatsapp', [
            'phone_number_id' => '123456789012345',
            'waba_id' => '987654321098765',
            'access_token' => 'not-a-real-token',
        ])->assertForbidden();

        $this->assertDatabaseMissing('faqs', ['question' => 'Can I book?']);
        $this->assertDatabaseCount('whatsapp_connections', 0);
    }

    public function test_staff_is_forbidden_from_remaining_admin_configuration_and_lifecycle_routes(): void
    {
        [$user, $tenant] = $this->makeMembership('staff');

        $holiday = $service = $staff = $faq = $connection = null;
        \App\Support\Tenancy\TenantContext::runForTenant($tenant->id, function () use (&$holiday, &$service, &$staff, &$faq, &$connection): void {
            $holiday = \App\Models\BusinessHoliday::query()->create([
                'date' => '2026-12-01', 'name' => 'Test holiday', 'is_closed' => true,
            ]);
            $service = \App\Models\Service::query()->create([
                'name' => 'Test service', 'price_amount' => 100, 'currency' => 'PKR',
                'duration_minutes' => 30, 'buffer_before_minutes' => 0,
                'buffer_after_minutes' => 0, 'active' => true,
            ]);
            $staff = \App\Models\Staff::query()->create([
                'name' => 'Test staff', 'active' => true,
            ]);
            $faq = \App\Models\Faq::query()->create([
                'question' => 'Test question?', 'answer' => 'Test answer',
                'locale' => 'en', 'status' => 'draft',
            ]);
            $connection = \App\Models\WhatsAppConnection::query()->create([
                'phone_number_id' => '123456789012399', 'waba_id' => '987654321098799',
                'access_token' => 'encrypted-test-token', 'status' => 'pending',
            ]);
        });

        $this->actingAs($user)->withSession(['current_tenant_id' => $tenant->id]);
        $unknownId = '00000000-0000-4000-8000-000000000001';

        $this->get('/business/calendar')->assertForbidden();
        $this->get('/business/calendar/calendars')->assertForbidden();
        $this->put('/business/calendar/default', ['calendar_id' => 'primary'])->assertForbidden();
        $this->put('/business/calendar/staff/'.$staff->id, ['calendar_id' => 'primary'])->assertForbidden();
        $this->get('/business/calendar/connect')->assertForbidden();
        $this->get('/business/whatsapp')->assertForbidden();
        $this->put('/business/appointments/policy', [])->assertForbidden();
        $this->post('/business/appointments/'.$unknownId.'/confirm-calendar')->assertForbidden();
        $this->post('/business/appointments/'.$unknownId.'/cancel')->assertForbidden();
        $this->post('/business/appointments/'.$unknownId.'/reschedule', [])->assertForbidden();
        $this->get('/business/staff/'.$staff->id.'/exceptions')->assertForbidden();
        $this->post('/business/staff/'.$staff->id.'/exceptions', [])->assertForbidden();
        $this->put('/business/staff/'.$staff->id.'/exceptions/'.$unknownId, [])->assertForbidden();
        $this->delete('/business/staff/'.$staff->id.'/exceptions/'.$unknownId)->assertForbidden();
        $this->get('/business/hours')->assertForbidden();
        $this->put('/business/hours', [])->assertForbidden();
        $this->get('/business/holidays')->assertForbidden();
        $this->post('/business/holidays', [])->assertForbidden();
        $this->put('/business/holidays/'.$holiday->id, [])->assertForbidden();
        $this->delete('/business/holidays/'.$holiday->id)->assertForbidden();
        $this->get('/business/services')->assertForbidden();
        $this->post('/business/services', [])->assertForbidden();
        $this->put('/business/services/'.$service->id, [])->assertForbidden();
        $this->delete('/business/services/'.$service->id)->assertForbidden();
        $this->get('/business/staff')->assertForbidden();
        $this->post('/business/staff', [])->assertForbidden();
        $this->put('/business/staff/'.$staff->id, [])->assertForbidden();
        $this->delete('/business/staff/'.$staff->id)->assertForbidden();
        $this->get('/business/staff/'.$staff->id.'/hours')->assertForbidden();
        $this->put('/business/staff/'.$staff->id.'/hours', [])->assertForbidden();
        $this->get('/business/faqs')->assertForbidden();
        $this->put('/business/faqs/'.$faq->id, [])->assertForbidden();
        $this->delete('/business/faqs/'.$faq->id)->assertForbidden();
        $this->put('/business/whatsapp/'.$connection->id, [])->assertForbidden();
        $this->post('/business/whatsapp/'.$connection->id.'/verify')->assertForbidden();
        $this->post('/business/whatsapp/'.$connection->id.'/templates/sync')->assertForbidden();
        $this->delete('/business/whatsapp/'.$connection->id)->assertForbidden();
        $this->delete('/business/calendar')->assertForbidden();

        $this->put('/conversations/'.$unknownId.'/assign', [])->assertForbidden();
        $this->post('/conversations/'.$unknownId.'/close')->assertForbidden();
        $this->post('/conversations/'.$unknownId.'/reopen')->assertForbidden();
    }

    private function makeMembership(string $role): array
    {
        $user = User::query()->create([
            'name' => 'Tenant User',
            'email' => $role.'@example.test',
            'password' => 'StrongPassword123',
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $tenant = Tenant::query()->create([
            'name' => 'Test Business',
            'slug' => 'test-'.$role,
        ]);
        $tenant->users()->attach($user->id, ['role' => $role, 'status' => 'active']);

        return [$user, $tenant];
    }
}
