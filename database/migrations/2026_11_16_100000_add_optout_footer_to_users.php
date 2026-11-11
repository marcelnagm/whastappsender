<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            // how every campaign message tells the customer how to stop: off | keyword | link | both
            $t->string('optout_mode', 10)->default('off');
            $t->string('optout_text', 400)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['optout_mode', 'optout_text']));
    }
};
