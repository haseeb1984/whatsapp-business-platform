<?php

namespace Tests\Feature;

use App\Models\Faq;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FaqTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_admin_can_create_publish_edit_archive_and_remove_faqs(): void
    {
        [$user, $tenant] = $this->workspace('faq-admin@example.test');
        $this->actingAs($user)->withSession(['current_tenant_id' => $tenant->id]);

        $payload = [
            'question' => 'What time do you open?',
            'answer' => 'We open at 9 AM.',
            'locale' => 'en',
            'status' => 'published',
        ];

        $this->post('/business/faqs', $payload)->assertRedirect();
        $faq = TenantContext::runForTenant($tenant->id, fn () => Faq::query()->firstOrFail());
        $this->assertNotNull($faq->published_at);

        $this->put('/business/faqs/'.$faq->id, [...$payload, 'answer' => 'We open at 10 AM.', 'status' => 'draft'])->assertRedirect();
        $this->assertDatabaseHas('faqs', [
            'id' => $faq->id,
            'answer' => 'We open at 10 AM.',
            'status' => 'draft',
            'published_at' => null,
        ]);

        $this->put('/business/faqs/'.$faq->id, [...$payload, 'status' => 'archived'])->assertRedirect();
        $this->assertDatabaseHas('faqs', ['id' => $faq->id, 'status' => 'archived']);

        $this->delete('/business/faqs/'.$faq->id)->assertRedirect();
        $this->assertDatabaseMissing('faqs', ['id' => $faq->id]);
    }

    public function test_faqs_are_isolated_between_tenants(): void
    {
        [, $tenantA] = $this->workspace('faq-a@example.test');
        [, $tenantB] = $this->workspace('faq-b@example.test');

        TenantContext::runForTenant($tenantA->id, function (): void {
            Faq::query()->create([
                'question' => 'Tenant A question',
                'answer' => 'Tenant A answer',
                'locale' => 'en',
                'status' => 'published',
                'published_at' => now(),
            ]);
        });
        TenantContext::runForTenant($tenantB->id, function (): void {
            $this->assertSame(0, Faq::query()->count());
        });
    }

    public function test_faq_validation_rejects_invalid_locale_status_and_duplicate_question(): void
    {
        [$user] = $this->workspace('faq-validation@example.test');
        $this->actingAs($user);

        $this->post('/business/faqs', [
            'question' => 'Question',
            'answer' => 'Answer',
            'locale' => 'EN-us',
            'status' => 'approved',
        ])->assertSessionHasErrors(['locale', 'status']);
        $this->assertDatabaseCount('faqs', 0);

        $valid = ['question' => 'Hours?', 'answer' => '9 AM', 'locale' => 'en', 'status' => 'draft'];
        $this->post('/business/faqs', $valid)->assertRedirect();
        $this->post('/business/faqs', $valid)->assertSessionHasErrors('question');
        $this->assertDatabaseCount('faqs', 1);
    }

    public function test_staff_cannot_manage_faqs(): void
    {
        [$user, $tenant] = $this->workspace('faq-staff@example.test', 'staff');
        $this->actingAs($user)->withSession(['current_tenant_id' => $tenant->id]);

        $this->get('/business/faqs')->assertForbidden();
        $this->post('/business/faqs', [])->assertForbidden();
    }

    private function workspace(string $email, string $role = 'business_admin'): array
    {
        $user = User::query()->create([
            'name' => 'FAQ User',
            'email' => $email,
            'password' => 'StrongPassword123',
                    ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $tenant = Tenant::query()->create([
            'name' => 'FAQ Workspace',
            'slug' => str_replace(['@', '.'], '-', $email),
        ]);
        $tenant->users()->attach($user->id, ['role' => $role, 'status' => 'active']);

        return [$user, $tenant];
    }
}
