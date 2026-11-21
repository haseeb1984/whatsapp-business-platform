<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->string('provider', 32);
            $table->string('provider_event_key', 64);
            $table->string('phone_number_id', 64)->nullable();
            $table->string('event_type', 64);
            $table->text('payload');
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('received_at');
            $table->timestampTz('processed_at')->nullable();
            $table->string('error_summary', 1000)->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_event_key']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->nullOnDelete();
            $table->index(['status', 'received_at']);
            $table->index(['tenant_id', 'status', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
