<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('booking_policies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->unique();
            $table->unsignedSmallInteger('minimum_notice_minutes')->default(60);
            $table->unsignedSmallInteger('booking_horizon_days')->default(60);
            $table->unsignedTinyInteger('slot_interval_minutes')->default(15);
            $table->unsignedSmallInteger('hold_minutes')->default(10);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE booking_policies ADD CONSTRAINT booking_policies_notice_check CHECK (minimum_notice_minutes BETWEEN 0 AND 10080)');
            DB::statement('ALTER TABLE booking_policies ADD CONSTRAINT booking_policies_horizon_check CHECK (booking_horizon_days BETWEEN 1 AND 365)');
            DB::statement('ALTER TABLE booking_policies ADD CONSTRAINT booking_policies_interval_check CHECK (slot_interval_minutes BETWEEN 5 AND 60 AND slot_interval_minutes % 5 = 0)');
            DB::statement('ALTER TABLE booking_policies ADD CONSTRAINT booking_policies_hold_check CHECK (hold_minutes BETWEEN 1 AND 60)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_policies');
    }
};
