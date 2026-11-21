<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('staff_availability_exceptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('staff_id');
            $table->date('date');
            $table->boolean('is_closed')->default(true);
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();
            $table->string('reason', 160)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'staff_id', 'date']);
            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'staff_id'])
                ->references(['tenant_id', 'id'])->on('staff_members')->cascadeOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE staff_availability_exceptions ADD CONSTRAINT staff_availability_exceptions_valid_hours_check CHECK ((is_closed AND opens_at IS NULL AND closes_at IS NULL) OR (NOT is_closed AND opens_at IS NOT NULL AND closes_at IS NOT NULL AND opens_at < closes_at))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_availability_exceptions');
    }
};
