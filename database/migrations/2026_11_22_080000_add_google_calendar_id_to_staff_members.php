<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('staff_members', function (Blueprint $table): void {
            $table->string('google_calendar_id', 255)->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('staff_members', function (Blueprint $table): void {
            $table->dropColumn('google_calendar_id');
        });
    }
};
