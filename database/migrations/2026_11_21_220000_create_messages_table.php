<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('conversation_id');
            $table->string('direction', 10);
            $table->string('message_type', 32);
            $table->text('body')->nullable();
            $table->string('provider_message_id', 128)->nullable();
            $table->string('delivery_status', 20)->default('received');
            $table->text('metadata')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'provider_message_id']);
            $table->foreign(['tenant_id', 'conversation_id'])
                ->references(['tenant_id', 'id'])->on('conversations')->cascadeOnDelete();
            $table->index(['tenant_id', 'conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
