<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('staff_members', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unique(['tenant_id', 'user_id'], 'staff_members_tenant_user_unique');
        });
    }

    public function down(): void
    {
        Schema::table('staff_members', function (Blueprint $table): void {
            $table->dropUnique('staff_members_tenant_user_unique');
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
