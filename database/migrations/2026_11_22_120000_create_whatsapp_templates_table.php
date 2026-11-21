<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('whatsapp_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('whatsapp_connection_id');
            $table->string('provider_template_id', 100);
            $table->string('name', 512);
            $table->string('language', 32);
            $table->string('category', 32)->nullable();
            $table->string('status', 32);
            $table->json('components')->nullable();
            $table->timestampTz('synced_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'provider_template_id']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'whatsapp_connection_id'])
                ->references(['tenant_id', 'id'])->on('whatsapp_connections')->cascadeOnDelete();
            $table->index(['tenant_id', 'status', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_templates');
    }
};
