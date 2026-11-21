<?php

namespace Tests\Feature;

use App\Models\Tenant;
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
