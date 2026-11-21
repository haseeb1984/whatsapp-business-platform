<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('conversation_booking_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('conversation_id');
            $table->string('state', 32);
            $table->uuid('service_id')->nullable();
            $table->uuid('staff_id')->nullable();
            $table->date('local_date')->nullable();
            $table->text('offered_services')->nullable();
            $table->text('offered_staff')->nullable();
            $table->text('offered_slots')->nullable();
            $table->uuid('appointment_id')->nullable();
            $table->timestampTz('expires_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'conversation_id']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'conversation_id'])->references(['tenant_id', 'id'])->on('conversations')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'service_id'])->references(['tenant_id', 'id'])->on('services')->restrictOnDelete();
            $table->foreign(['tenant_id', 'staff_id'])->references(['tenant_id', 'id'])->on('staff_members')->restrictOnDelete();
            $table->foreign(['tenant_id', 'appointment_id'])->references(['tenant_id', 'id'])->on('appointments')->restrictOnDelete();
            $table->index(['tenant_id', 'state', 'expires_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE conversation_booking_sessions ADD CONSTRAINT conversation_booking_sessions_state_check CHECK (state IN ('awaiting_service', 'awaiting_date', 'awaiting_staff', 'awaiting_slot', 'hold_created', 'cancelled', 'expired'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_booking_sessions');
    }
};
