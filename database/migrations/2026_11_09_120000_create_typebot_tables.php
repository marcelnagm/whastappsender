<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('typebot_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->boolean('enabled')->default(false);
            $table->string('base_url', 200)->default('https://typebot.io');   // viewer URL (self-hosted works too)
            $table->string('public_id', 120)->nullable();                     // "public id" of the published bot
            $table->unsignedBigInteger('instance_id')->nullable();            // null = every number
            $table->string('handoff_keywords', 255)->nullable();              // customer words that call a person
            $table->string('on_finish', 10)->default('none');                 // none | handoff
            $table->text('handoff_message')->nullable();
            $table->unsignedBigInteger('handoff_to')->nullable();             // person who receives it (null = normal distribution)
            $table->unsignedSmallInteger('expire_minutes')->default(720);     // idle time after which the flow starts over
            $table->boolean('only_outside_hours')->default(false);
            $table->timestamps();
        });

        Schema::create('typebot_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('chat_id')->unique();
            $table->string('session_id', 120)->nullable();
            $table->string('status', 10)->default('active');                  // active | finished | handoff
            $table->text('choices')->nullable();                              // JSON list of the options of the pending choice input
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('typebot_sessions');
        Schema::dropIfExists('typebot_settings');
    }
};
