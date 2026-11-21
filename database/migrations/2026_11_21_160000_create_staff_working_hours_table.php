<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('staff_working_hours', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('staff_id');
            $table->unsignedTinyInteger('weekday');
            $table->boolean('is_closed')->default(false);
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'staff_id', 'weekday']);
            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'staff_id'])
                ->references(['tenant_id', 'id'])->on('staff_members')->cascadeOnDelete();
            $table->check('weekday BETWEEN 0 AND 6');
            $table->check('(is_closed = true AND opens_at IS NULL AND closes_at IS NULL) OR (is_closed = false AND opens_at IS NOT NULL AND closes_at IS NOT NULL AND opens_at < closes_at)');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_working_hours');
    }
};
