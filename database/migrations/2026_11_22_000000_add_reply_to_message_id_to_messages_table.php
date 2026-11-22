<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->uuid('reply_to_message_id')->nullable()->after('conversation_id');
            $table->unique(['tenant_id', 'reply_to_message_id'], 'messages_tenant_reply_unique');
            $table->foreign(['tenant_id', 'reply_to_message_id'], 'messages_reply_same_tenant_fk')
                ->references(['tenant_id', 'id'])->on('messages')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            if (DB::getDriverName() === 'sqlite') {
                $table->dropForeign(['tenant_id', 'reply_to_message_id']);
            } else {
                $table->dropForeign('messages_reply_same_tenant_fk');
            }
            $table->dropUnique('messages_tenant_reply_unique');
            $table->dropColumn('reply_to_message_id');
        });
    }
};
