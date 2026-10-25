<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('chats', function (Blueprint $table) {
            // Profile pictures are downloaded and stored locally: WhatsApp CDN links are signed and expire in days.
            $table->string('avatar_path')->nullable()->after('avatar_url');
            // When to look at this contact's picture again (null = never checked)
            $table->timestamp('avatar_next_check_at')->nullable()->after('avatar_checked_at');
        });
    }

    public function down()
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->dropColumn(['avatar_path', 'avatar_next_check_at']);
        });
    }
};
