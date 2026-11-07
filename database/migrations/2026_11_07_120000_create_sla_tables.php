<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sla_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->boolean('enabled')->default(false);
            $table->unsignedSmallInteger('first_response_minutes')->default(15);
            $table->unsignedSmallInteger('escalate_after_minutes')->default(30);   // extra minutes after the breach
            $table->unsignedBigInteger('escalate_to')->nullable();
            $table->boolean('reassign')->default(false);
            $table->boolean('business_only')->default(true);                       // the clock only runs inside opening hours
            $table->timestamps();
        });

        // One row per breach (and per escalation) of a wait for a first answer
        Schema::create('sla_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('chat_id')->index();
            $table->unsignedBigInteger('inbound_message_id');
            $table->string('kind', 12);                      // breach | escalation
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->unsignedInteger('waited_minutes')->default(0);
            $table->timestamp('resolved_at')->nullable();    // answered afterwards
            $table->timestamps();
            $table->unique(['chat_id', 'inbound_message_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sla_events');
        Schema::dropIfExists('sla_settings');
    }
};
