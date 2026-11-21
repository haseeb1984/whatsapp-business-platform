<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('faqs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('question', 500);
            $table->text('answer');
            $table->string('locale', 12)->default('en');
            $table->string('status', 20)->default('draft');
            $table->timestampTz('published_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'locale', 'question']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'status', 'locale']);
            $table->check("status IN ('draft', 'published', 'archived')");
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faqs');
    }
};
