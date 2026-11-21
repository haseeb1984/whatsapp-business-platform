<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('whatsapp_connections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('phone_number_id', 64)->unique();
            $table->string('waba_id', 64);
            $table->string('display_phone_number', 32)->nullable();
            $table->string('verified_name', 160)->nullable();
            $table->text('access_token');
            $table->string('status', 20)->default('pending');
            $table->timestampTz('verified_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_connections');
    }
};
