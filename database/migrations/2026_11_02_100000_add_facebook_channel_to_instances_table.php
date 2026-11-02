<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instances', function (Blueprint $table) {
            $table->text('facebook_token')->nullable();
            $table->string('facebook_page_id', 40)->nullable()->index();
            $table->string('facebook_page_name', 120)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('instances', function (Blueprint $table) {
            $table->dropIndex(['facebook_page_id']);
            $table->dropColumn(['facebook_token', 'facebook_page_id', 'facebook_page_name']);
        });
    }
};
