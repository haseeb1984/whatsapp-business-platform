<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tenant_invitations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('email', 254);
            $table->string('role', 32);
            $table->char('token_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestamps();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'email', 'accepted_at', 'revoked_at'], 'tenant_invites_pending_lookup');
            $table->index(['expires_at', 'accepted_at', 'revoked_at'], 'tenant_invites_expiry_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_invitations');
    }
};
