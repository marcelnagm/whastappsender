<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // "Web chat" channel: the widget settings (title, welcome text, colour, allowed sites) live with the instance
        Schema::table('instances', function (Blueprint $t) {
            $t->json('webchat_config')->nullable();
        });

        // a filter the user saved to open again with one click (contacts, deals, tasks...)
        Schema::create('saved_views', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->index();       // account owner
            $t->unsignedBigInteger('created_by')->nullable(); // who saved it (team member)
            $t->string('scope', 20);                           // contacts | pipeline | tasks
            $t->string('name', 80);
            $t->string('query', 1500)->default('');           // the query string, without "?"
            $t->boolean('shared')->default(true);              // visible to the whole team, or only to its creator
            $t->timestamps();
            $t->index(['user_id', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_views');
        Schema::table('instances', fn (Blueprint $t) => $t->dropColumn('webchat_config'));
    }
};
