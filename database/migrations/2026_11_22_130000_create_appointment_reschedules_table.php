<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('appointment_reschedules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('appointment_id');
            $table->timestampTz('old_starts_at');
            $table->timestampTz('old_ends_at');
            $table->timestampTz('old_reserved_starts_at');
            $table->timestampTz('old_reserved_ends_at');
            $table->timestampTz('new_starts_at');
            $table->timestampTz('new_ends_at');
            $table->timestampTz('new_reserved_starts_at');
            $table->timestampTz('new_reserved_ends_at');
            $table->string('status', 24)->default('pending');
            $table->string('idempotency_key', 128);
            $table->string('last_error', 120)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->foreign(['tenant_id', 'appointment_id'])
                ->references(['tenant_id', 'id'])->on('appointments')->cascadeOnDelete();
            $table->index(['tenant_id', 'status', 'new_reserved_starts_at'], 'appointment_reschedules_pending_lookup');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE appointment_reschedules ADD CONSTRAINT appointment_reschedules_status_check CHECK (status IN ('pending', 'unknown', 'synced', 'failed', 'cancelled'))");
            DB::statement("ALTER TABLE appointment_reschedules ADD CONSTRAINT appointment_reschedules_valid_times_check CHECK (old_reserved_starts_at <= old_starts_at AND old_starts_at < old_ends_at AND old_ends_at <= old_reserved_ends_at AND new_reserved_starts_at <= new_starts_at AND new_starts_at < new_ends_at AND new_ends_at <= new_reserved_ends_at)");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_reschedules');
    }
};
