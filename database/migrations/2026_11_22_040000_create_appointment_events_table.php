<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('appointment_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('appointment_id');
            $table->string('event_type', 40);
            $table->string('actor_type', 24)->default('system');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->text('metadata')->nullable();
            $table->timestamps();

            $table->foreign(['tenant_id', 'appointment_id'])
                ->references(['tenant_id', 'id'])->on('appointments')->cascadeOnDelete();
            $table->foreign('actor_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['tenant_id', 'appointment_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_events');
    }
};
