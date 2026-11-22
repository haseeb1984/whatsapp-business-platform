<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 191);
            $table->string('subject_type', 120)->nullable();
            $table->string('subject_id', 128)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('metadata')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants')->nullOnDelete();
            $table->index(['tenant_id', 'created_at'], 'audit_events_tenant_created_lookup');
            $table->index(['actor_user_id', 'created_at'], 'audit_events_actor_created_lookup');
            $table->index(['event', 'created_at'], 'audit_events_event_created_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};
