<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            // A Telegram / Instagram / TikTok contact points to the WhatsApp contact of the same person
            $table->unsignedBigInteger('linked_contact_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropIndex(['linked_contact_id']);
            $table->dropColumn('linked_contact_id');
        });
    }
};
