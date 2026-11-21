<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('staff_services', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('staff_id');
            $table->uuid('service_id');
            $table->timestamps();

            $table->unique(['tenant_id', 'staff_id', 'service_id']);
            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'staff_id'])
                ->references(['tenant_id', 'id'])->on('staff_members')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'service_id'])
                ->references(['tenant_id', 'id'])->on('services')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_services');
    }
};
