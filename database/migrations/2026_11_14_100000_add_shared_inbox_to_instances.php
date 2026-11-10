<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('instances', function (Blueprint $t) {
            $t->unsignedBigInteger('default_assignee')->nullable();   // who receives the new conversations of this inbox (shared mailbox)
            $t->text('email_signature')->nullable();                  // added at the end of every e-mail sent from this mailbox
        });
    }

    public function down(): void
    {
        Schema::table('instances', fn (Blueprint $t) => $t->dropColumn(['default_assignee', 'email_signature']));
    }
};
