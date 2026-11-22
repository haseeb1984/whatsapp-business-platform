<?php

namespace Tests\Feature;

use App\Http\Middleware\RecordAuditEvent;
use App\Models\AuditEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class AdminAuditEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_mutation_audit_records_actor_tenant_subject_and_response_without_payload(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Audit Workspace', 'slug' => 'audit-workspace']);
        $user = $this->user();
        $request = Request::create('/business/services/service-123', 'PUT', [
            'name' => 'Private service name',
            'access_token' => 'must-not-be-recorded',
        ]);
        $request->setUserResolver(fn () => $user);
        $request->attributes->set(TenantContext::class, new TenantContext($tenant, 'business_admin'));

        $response = app(RecordAuditEvent::class)->handle($request, fn () => response('ok', 200));

        $this->assertSame(200, $response->getStatusCode());
        $event = AuditEvent::query()->firstOrFail();
        $this->assertSame($tenant->id, $event->tenant_id);
        $this->assertSame($user->id, $event->actor_user_id);
        $this->assertSame('PUT business/services/service-123', $event->event);
        $this->assertNull($event->subject_id);
        $this->assertSame(['method' => 'PUT', 'status_code' => 200], $event->metadata);
        $this->assertStringNotContainsString('must-not-be-recorded', json_encode($event->metadata));
    }

    public function test_read_only_requests_are_not_written_to_audit_log(): void
    {
        $request = Request::create('/platform', 'GET');

        app(RecordAuditEvent::class)->handle($request, fn () => response('ok', 200));

        $this->assertDatabaseCount('audit_events', 0);
    }

    private function user(): User
    {
        return User::query()->create([
            'name' => 'Audit Admin',
            'email' => 'audit-admin@example.test',
            'password' => 'StrongPassword123',
        ]);
    }
}
