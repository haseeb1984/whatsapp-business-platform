<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('ai_usage_daily', function (Blueprint $table): void {
            $table->unsignedBigInteger('reserved_tokens')->default(0)->after('tokens');
        });
    }

    public function down(): void
    {
        Schema::table('ai_usage_daily', function (Blueprint $table): void {
            $table->dropColumn('reserved_tokens');
        });
    }
};
