<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instances', function (Blueprint $table) {
            $table->text('tiktok_token')->nullable();
            $table->text('tiktok_refresh_token')->nullable();
            $table->string('tiktok_open_id', 80)->nullable()->index();
            $table->string('tiktok_username', 80)->nullable();
            $table->timestamp('tiktok_token_expires_at')->nullable();
            $table->timestamp('tiktok_refresh_expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('instances', function (Blueprint $table) {
            $table->dropIndex(['tiktok_open_id']);
            $table->dropColumn(['tiktok_token', 'tiktok_refresh_token', 'tiktok_open_id', 'tiktok_username', 'tiktok_token_expires_at', 'tiktok_refresh_expires_at']);
        });
    }
};
