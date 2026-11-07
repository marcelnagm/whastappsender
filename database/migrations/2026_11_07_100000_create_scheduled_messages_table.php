<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // A text message of the chat that goes out later, at a chosen time
        Schema::create('scheduled_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('chat_id')->index();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->text('body');
            $table->timestamp('send_at');
            $table->boolean('cancel_on_reply')->default(true);   // a customer who answered first does not need the nudge
            $table->string('status', 10)->default('pending');    // pending | sent | failed | canceled
            $table->string('note', 200)->nullable();             // why it failed / was canceled
            $table->unsignedBigInteger('message_id')->nullable(); // the chat message created when it went out
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'send_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_messages');
    }
};
