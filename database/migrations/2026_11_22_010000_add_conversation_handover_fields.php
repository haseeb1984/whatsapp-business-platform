<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->timestampTz('handover_requested_at')->nullable();
            $table->string('handover_reason', 160)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropColumn(['handover_requested_at', 'handover_reason']);
        });
    }
};
