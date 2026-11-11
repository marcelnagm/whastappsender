<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('chats', function (Blueprint $t) {
            // "pending reply": the team marked the conversation as owing the customer an answer
            $t->timestamp('pending_at')->nullable()->index();
            $t->unsignedBigInteger('pending_by')->nullable();
            // the customer (or the bot) asked for a person; cleared when a person answers or the chat is closed
            $t->timestamp('needs_human_at')->nullable()->index();
            $t->string('needs_human_reason', 160)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('chats', fn (Blueprint $t) => $t->dropColumn(['pending_at', 'pending_by', 'needs_human_at', 'needs_human_reason']));
    }
};
