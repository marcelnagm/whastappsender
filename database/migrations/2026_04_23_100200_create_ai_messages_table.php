<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ai_session_id');
            $table->string('direction', 20);
            $table->string('role', 20);
            $table->string('channel_message_id')->nullable();
            $table->longText('content');
            $table->unsignedInteger('tokens_in')->nullable();
            $table->unsignedInteger('tokens_out')->nullable();
            $table->string('provider', 40)->default('groq');
            $table->string('model')->nullable();
            $table->string('status', 20)->default('ok');
            $table->text('error')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->foreign('ai_session_id')->references('id')->on('ai_sessions')->onDelete('cascade');

            $table->index(['ai_session_id', 'created_at']);
            $table->index('channel_message_id');
            $table->index(['provider', 'model']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_messages');
    }
};
