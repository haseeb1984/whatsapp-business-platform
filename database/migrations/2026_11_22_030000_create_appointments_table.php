<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('customer_id');
            $table->uuid('service_id');
            $table->uuid('staff_id');
            $table->string('service_name', 120);
            $table->decimal('price_amount', 12, 2);
            $table->char('currency', 3);
            $table->unsignedSmallInteger('duration_minutes');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->timestampTz('reserved_starts_at');
            $table->timestampTz('reserved_ends_at');
            $table->string('status', 24)->default('pending_hold');
            $table->string('source', 24)->default('whatsapp');
            $table->string('idempotency_key', 128);
            $table->string('calendar_sync_status', 24)->default('pending');
            $table->string('external_event_id', 255)->nullable();
            $table->timestampTz('hold_expires_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->foreign(['tenant_id', 'customer_id'])
                ->references(['tenant_id', 'id'])->on('customers')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'service_id'])
                ->references(['tenant_id', 'id'])->on('services')->restrictOnDelete();
            $table->foreign(['tenant_id', 'staff_id'])
                ->references(['tenant_id', 'id'])->on('staff_members')->restrictOnDelete();
            $table->index(['tenant_id', 'staff_id', 'reserved_starts_at', 'reserved_ends_at', 'status'], 'appointments_staff_slot_lookup');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE appointments ADD CONSTRAINT appointments_valid_times_check CHECK (reserved_starts_at <= starts_at AND starts_at < ends_at AND ends_at <= reserved_ends_at)");
            DB::statement("ALTER TABLE appointments ADD CONSTRAINT appointments_valid_price_check CHECK (price_amount >= 0 AND duration_minutes BETWEEN 5 AND 1440)");
            DB::statement("ALTER TABLE appointments ADD CONSTRAINT appointments_status_check CHECK (status IN ('pending_hold', 'calendar_pending', 'confirmed', 'cancelled', 'expired', 'failed'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
