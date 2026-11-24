<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tenant_user', function (Blueprint $table): void {
            $table->jsonb('notification_preferences')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenant_user', function (Blueprint $table): void {
            $table->dropColumn('notification_preferences');
        });
    }
};
