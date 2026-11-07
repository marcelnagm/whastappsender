<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Opening hours of the account and the automatic messages that depend on them (one row per account)
        Schema::create('service_hours', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->string('timezone', 64)->default('America/Sao_Paulo');
            $table->string('business_days', 20)->default('1,2,3,4,5');
            $table->string('business_start', 5)->default('09:00');
            $table->string('business_end', 5)->default('18:00');
            $table->boolean('away_enabled')->default(false);
            $table->text('away_message')->nullable();
            $table->unsignedSmallInteger('away_every_hours')->default(8);  // not again to the same conversation before this
            $table->boolean('greeting_enabled')->default(false);
            $table->text('greeting_message')->nullable();
            $table->timestamps();
        });

        Schema::table('chats', function (Blueprint $table) {
            $table->timestamp('greeted_at')->nullable();
            $table->timestamp('away_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('chats', fn (Blueprint $t) => $t->dropColumn(['greeted_at', 'away_sent_at']));
        Schema::dropIfExists('service_hours');
    }
};
