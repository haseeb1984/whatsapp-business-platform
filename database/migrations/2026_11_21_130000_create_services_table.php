<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->decimal('price_amount', 12, 2);
            $table->char('currency', 3);
            $table->unsignedSmallInteger('duration_minutes');
            $table->unsignedSmallInteger('buffer_before_minutes')->default(0);
            $table->unsignedSmallInteger('buffer_after_minutes')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
            $table->unique(['tenant_id', 'id']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'active']);
            $table->check('price_amount >= 0');
            $table->check('duration_minutes BETWEEN 5 AND 1440');
            $table->check('buffer_before_minutes <= 240 AND buffer_after_minutes <= 240');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
