<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\Concerns\BelongsToTenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

class TenantOwnedModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('tenant_owned_test_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('value');
            $table->timestamps();
        });
    }

    private function setTenant(Tenant $tenant): void
    {
        app()->instance(TenantContext::class, new TenantContext($tenant, 'business_admin'));
    }

    public function test_it_scopes_reads_and_mutations_to_the_current_tenant(): void
    {
        $tenantA = Tenant::query()->create(['name' => 'Tenant A', 'slug' => 'tenant-a']);
        $tenantB = Tenant::query()->create(['name' => 'Tenant B', 'slug' => 'tenant-b']);

        $this->setTenant($tenantA);
        $recordA = TenantOwnedTestRecord::query()->create(['value' => 'A']);
        $this->setTenant($tenantB);
        $recordB = TenantOwnedTestRecord::query()->create(['value' => 'B']);

        $this->setTenant($tenantA);
        $this->assertSame(1, TenantOwnedTestRecord::query()->count());
        $this->assertSame('A', TenantOwnedTestRecord::query()->firstOrFail()->value);
        $this->assertSame(0, TenantOwnedTestRecord::query()->whereKey($recordB->id)->update(['value' => 'tampered']));
        $this->assertSame(0, TenantOwnedTestRecord::query()->whereKey($recordB->id)->delete());

        $this->expectException(LogicException::class);
        TenantOwnedTestRecord::query()->whereKey($recordA->id)->update(['tenant_id' => $tenantB->id]);
    }

    public function test_it_assigns_the_context_tenant_and_rejects_tenant_id_tampering(): void
    {
        $tenantA = Tenant::query()->create(['name' => 'Tenant A', 'slug' => 'tenant-a']);
        $tenantB = Tenant::query()->create(['name' => 'Tenant B', 'slug' => 'tenant-b']);
        $this->setTenant($tenantA);

        $record = TenantOwnedTestRecord::query()->create(['value' => 'safe']);
        $this->assertSame($tenantA->id, $record->tenant_id);

        $this->expectException(LogicException::class);
        TenantOwnedTestRecord::query()->create(['tenant_id' => $tenantB->id, 'value' => 'unsafe']);
    }


    public function test_background_work_gets_an_explicit_context_and_restores_the_previous_context(): void
    {
        $tenantA = Tenant::query()->create(['name' => 'Tenant A', 'slug' => 'tenant-a']);
        $tenantB = Tenant::query()->create(['name' => 'Tenant B', 'slug' => 'tenant-b']);
        $this->setTenant($tenantA);

        $result = TenantContext::runForTenant($tenantB->id, function (): string {
            $this->assertSame('system', app(TenantContext::class)->role());
            return app(TenantContext::class)->id();
        });

        $this->assertSame($tenantB->id, $result);
        $this->assertSame($tenantA->id, app(TenantContext::class)->id());
    }

    public function test_background_work_rejects_inactive_tenants(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Paused Business',
            'slug' => 'paused-business',
            'status' => 'suspended',
        ]);

        $this->expectException(LogicException::class);
        TenantContext::runForTenant($tenant->id, fn () => 'should not run');
    }

    public function test_every_business_owned_model_uses_the_tenant_scope_contract(): void
    {
        $tenantOwnedModels = [
            \App\Models\Appointment::class,
            \App\Models\AppointmentEvent::class,
            \App\Models\AppointmentReminder::class,
            \App\Models\AppointmentReschedule::class,
            \App\Models\BookingPolicy::class,
            \App\Models\BusinessHoliday::class,
            \App\Models\BusinessHour::class,
            \App\Models\BusinessProfile::class,
            \App\Models\Conversation::class,
            \App\Models\ConversationBookingSession::class,
            \App\Models\ConversationNote::class,
            \App\Models\Customer::class,
            \App\Models\Faq::class,
            \App\Models\GoogleCalendarConnection::class,
            \App\Models\Message::class,
            \App\Models\Service::class,
            \App\Models\Staff::class,
            \App\Models\StaffAvailabilityException::class,
            \App\Models\StaffService::class,
            \App\Models\StaffWorkingHour::class,
            \App\Models\WhatsAppConnection::class,
            \App\Models\WhatsAppTemplate::class,
        ];

        foreach ($tenantOwnedModels as $model) {
            $this->assertContains(
                BelongsToTenant::class,
                class_uses($model),
                $model.' must use the fail-closed tenant ownership contract.',
            );
        }
    }

    public function test_tenant_owned_bulk_insert_operations_are_blocked(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Tenant A', 'slug' => 'tenant-a']);
        $this->setTenant($tenant);

        try {
            TenantOwnedTestRecord::query()->insert(['id' => (string) str()->uuid(), 'tenant_id' => $tenant->id, 'value' => 'unsafe']);
            $this->fail('Bulk insert should be blocked.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('Bulk inserts are disabled', $exception->getMessage());
        }

        try {
            TenantOwnedTestRecord::query()->upsert([
                ['id' => (string) str()->uuid(), 'tenant_id' => $tenant->id, 'value' => 'unsafe'],
            ], ['id'], ['value']);
            $this->fail('Bulk upsert should be blocked.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('Bulk upserts are disabled', $exception->getMessage());
        }

        $this->assertSame(0, TenantOwnedTestRecord::query()->count());
    }

    public function test_tenant_owned_update_or_insert_is_blocked(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Tenant A', 'slug' => 'tenant-a']);
        $this->setTenant($tenant);

        $this->expectException(LogicException::class);
        TenantOwnedTestRecord::query()->updateOrInsert(
            ['value' => 'unsafe'],
            ['tenant_id' => $tenant->id],
        );
    }

    public function test_tenant_owned_queries_fail_closed_without_context(): void
    {
        app()->forgetInstance(TenantContext::class);

        $this->expectException(LogicException::class);
        TenantOwnedTestRecord::query()->count();
    }
}

class TenantOwnedTestRecord extends Model
{
    use HasUuids;
    use \App\Models\Concerns\BelongsToTenant;

    protected $table = 'tenant_owned_test_records';
    protected $fillable = ['tenant_id', 'value'];
}
