<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('business_holidays', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->date('date');
            $table->string('name', 160);
            $table->boolean('is_closed')->default(true);
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'date']);
            $table->unique(['tenant_id', 'id']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->check('(is_closed = true AND opens_at IS NULL AND closes_at IS NULL) OR (is_closed = false AND opens_at IS NOT NULL AND closes_at IS NOT NULL AND opens_at < closes_at)');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_holidays');
    }
};
