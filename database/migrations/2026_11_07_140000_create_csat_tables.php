<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
        });

        Schema::create('csat_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->boolean('enabled')->default(false);
            $table->string('scale', 6)->default('csat');          // csat (1-5) | nps (0-10)
            $table->string('question', 500)->nullable();
            $table->string('thanks', 500)->nullable();
            $table->unsignedSmallInteger('expire_hours')->default(24);
            $table->timestamps();
        });

        Schema::create('csat_surveys', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('chat_id')->index();
            $table->unsignedBigInteger('agent_id')->nullable();   // who handled it when it was closed
            $table->string('scale', 6);
            $table->string('status', 10)->default('sent');        // sent | answered | expired
            $table->smallInteger('score')->nullable();
            $table->timestamp('sent_at');
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('csat_surveys');
        Schema::dropIfExists('csat_settings');
        Schema::table('chats', fn (Blueprint $table) => $table->dropColumn(['closed_at', 'closed_by']));
    }
};
