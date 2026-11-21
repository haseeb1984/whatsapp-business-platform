<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('appointment_reminders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('appointment_id');
            $table->string('channel', 24)->default('whatsapp');
            $table->string('reminder_type', 24);
            $table->timestampTz('scheduled_at');
            $table->string('status', 32)->default('pending');
            $table->string('idempotency_key', 160);
            $table->timestampTz('queued_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('sent_at')->nullable();
            $table->string('provider_message_id', 255)->nullable();
            $table->string('last_error', 160)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->foreign(['tenant_id', 'appointment_id'])
                ->references(['tenant_id', 'id'])->on('appointments')->cascadeOnDelete();
            $table->index(['tenant_id', 'status', 'scheduled_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE appointment_reminders ADD CONSTRAINT appointment_reminders_channel_check CHECK (channel IN ('whatsapp'))");
            DB::statement("ALTER TABLE appointment_reminders ADD CONSTRAINT appointment_reminders_type_check CHECK (reminder_type IN ('24_hours', '2_hours'))");
            DB::statement("ALTER TABLE appointment_reminders ADD CONSTRAINT appointment_reminders_status_check CHECK (status IN ('pending', 'queued', 'processing', 'sent', 'unknown', 'failed', 'blocked_template_required', 'blocked_handover', 'cancelled'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_reminders');
    }
};
