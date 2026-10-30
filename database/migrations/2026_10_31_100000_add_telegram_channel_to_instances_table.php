<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A Telegram bot is stored as an instance with channel = 'telegram' (chat, AI agents and plan limits then work unchanged). */
return new class extends Migration {
    public function up()
    {
        Schema::table('instances', function (Blueprint $table) {
            $table->string('channel', 20)->default('whatsapp')->index();
            $table->text('telegram_token')->nullable();          // encrypted by the model
            $table->string('telegram_username', 64)->nullable();
            $table->string('telegram_secret', 64)->nullable();   // X-Telegram-Bot-Api-Secret-Token
        });
    }

    public function down()
    {
        Schema::table('instances', function (Blueprint $table) {
            $table->dropColumn(['channel', 'telegram_token', 'telegram_username', 'telegram_secret']);
        });
    }
};
