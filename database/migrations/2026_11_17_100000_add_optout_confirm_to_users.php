<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            // answer "done, you will not receive more messages" when a customer opts out (or in) by writing
            $t->boolean('optout_confirm')->default(true);
            $t->string('optout_confirm_text', 400)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['optout_confirm', 'optout_confirm_text']));
    }
};
