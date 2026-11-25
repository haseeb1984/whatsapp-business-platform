<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ai_usage_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('tenant_id')->nullable();
            $table->string('provider', 32);
            $table->string('model', 128);
            $table->string('outcome', 32);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedBigInteger('prompt_tokens')->nullable();
            $table->unsignedBigInteger('completion_tokens')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants')->nullOnDelete();
            $table->index(['tenant_id', 'created_at'], 'ai_usage_tenant_created');
            $table->index(['outcome', 'created_at'], 'ai_usage_outcome_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_events');
    }
};
