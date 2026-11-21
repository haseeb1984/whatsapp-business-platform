<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->uuid('reminder_id')->nullable();
            $table->unique(['tenant_id', 'reminder_id']);
            $table->foreign(['tenant_id', 'reminder_id'])
                ->references(['tenant_id', 'id'])->on('appointment_reminders')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id', 'reminder_id']);
            $table->dropUnique(['tenant_id', 'reminder_id']);
            $table->dropColumn('reminder_id');
        });
    }
};
