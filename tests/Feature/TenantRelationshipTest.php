<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Database\QueryException;
use Tests\TestCase;

class TenantRelationshipTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_rejects_a_conversation_linked_to_another_tenants_customer(): void
    {
        $owner = Tenant::query()->create(['name' => 'Owner', 'slug' => 'owner-tenant']);
        $other = Tenant::query()->create(['name' => 'Other', 'slug' => 'other-tenant']);
        $customerId = (string) Str::uuid();

        DB::table('customers')->insert([
            'id' => $customerId,
            'tenant_id' => $owner->id,
            'whatsapp_wa_id' => 'wa-owner-1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('conversations')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $other->id,
            'customer_id' => $customerId,
            'status' => 'open',
            'automation_state' => 'AUTO_ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_database_rejects_staff_service_links_across_tenants(): void
    {
        $owner = Tenant::query()->create(['name' => 'Owner', 'slug' => 'owner-staff']);
        $other = Tenant::query()->create(['name' => 'Other', 'slug' => 'other-staff']);
        $staffId = (string) Str::uuid();
        $serviceId = (string) Str::uuid();

        DB::table('staff_members')->insert([
            'id' => $staffId, 'tenant_id' => $owner->id, 'name' => 'Owner Staff',
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('services')->insert([
            'id' => $serviceId, 'tenant_id' => $owner->id, 'name' => 'Owner Service',
            'price_amount' => 100, 'currency' => 'PKR', 'duration_minutes' => 30,
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('staff_services')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $other->id,
            'staff_id' => $staffId, 'service_id' => $serviceId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_database_rejects_messages_attached_to_another_tenants_conversation(): void
    {
        $owner = Tenant::query()->create(['name' => 'Owner', 'slug' => 'owner-message']);
        $other = Tenant::query()->create(['name' => 'Other', 'slug' => 'other-message']);
        $customerId = (string) Str::uuid();
        $conversationId = (string) Str::uuid();

        DB::table('customers')->insert([
            'id' => $customerId, 'tenant_id' => $owner->id, 'whatsapp_wa_id' => 'wa-owner-message',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('conversations')->insert([
            'id' => $conversationId, 'tenant_id' => $owner->id, 'customer_id' => $customerId,
            'status' => 'open', 'automation_state' => 'AUTO_ACTIVE',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('messages')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $other->id,
            'conversation_id' => $conversationId, 'direction' => 'inbound',
            'message_type' => 'text', 'delivery_status' => 'received',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_database_rejects_message_replies_across_tenants(): void
    {
        $owner = Tenant::query()->create(['name' => 'Owner', 'slug' => 'owner-reply']);
        $other = Tenant::query()->create(['name' => 'Other', 'slug' => 'other-reply']);
        $customerId = (string) Str::uuid();
        $conversationId = (string) Str::uuid();
        $messageId = (string) Str::uuid();

        DB::table('customers')->insert([
            'id' => $customerId, 'tenant_id' => $owner->id, 'whatsapp_wa_id' => 'wa-owner-reply',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('conversations')->insert([
            'id' => $conversationId, 'tenant_id' => $owner->id, 'customer_id' => $customerId,
            'status' => 'open', 'automation_state' => 'AUTO_ACTIVE',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('messages')->insert([
            'id' => $messageId, 'tenant_id' => $owner->id, 'conversation_id' => $conversationId,
            'direction' => 'inbound', 'message_type' => 'text', 'delivery_status' => 'received',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('messages')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $other->id,
            'conversation_id' => (string) Str::uuid(), 'reply_to_message_id' => $messageId,
            'direction' => 'outbound', 'message_type' => 'text', 'delivery_status' => 'sent',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_a_user_can_belong_to_a_tenant_with_a_role(): void
    {
        $user = User::query()->create([
            'name' => 'Workspace Owner',
            'email' => 'owner@example.test',
            'password' => 'test-password',
        ]);

        $tenant = Tenant::query()->create([
            'name' => 'Example Business',
            'slug' => 'example-business',
            'timezone' => 'Asia/Karachi',
            'locale' => 'en',
        ]);

        $tenant->users()->attach($user->id, [
            'role' => 'business_admin',
            'status' => 'active',
        ]);

        $this->assertNotEmpty($tenant->id);
        $this->assertTrue($tenant->users()->whereKey($user->id)->exists());
        $this->assertSame('business_admin', $tenant->users()->first()->pivot->role);
    }
}
