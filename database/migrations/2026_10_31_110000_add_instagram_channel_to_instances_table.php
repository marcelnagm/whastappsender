<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instances', function (Blueprint $table) {
            $table->text('instagram_token')->nullable();
            $table->string('instagram_id', 40)->nullable()->index();
            $table->string('instagram_username', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('instances', function (Blueprint $table) {
            $table->dropIndex(['instagram_id']);
            $table->dropColumn(['instagram_token', 'instagram_id', 'instagram_username']);
        });
    }
};
