<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->uuid('id')->primary(); $table->string('name'); $table->string('slug')->unique();
            $table->string('status', 24)->default('active')->index(); $table->string('timezone', 64)->default('UTC');
            $table->string('locale', 12)->default('en'); $table->jsonb('settings')->nullable(); $table->timestamps();
        });
        Schema::create('tenant_user', function (Blueprint $table): void {
            $table->uuid('tenant_id'); $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 32)->default('business_admin'); $table->string('status', 24)->default('active');
            $table->timestamps(); $table->primary(['tenant_id', 'user_id']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['user_id', 'status']);
        });
    }
    public function down(): void { Schema::dropIfExists('tenant_user'); Schema::dropIfExists('tenants'); }
};
