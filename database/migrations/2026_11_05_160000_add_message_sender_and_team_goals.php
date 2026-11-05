<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // who wrote an outgoing message: the attendant (sent_by) and what produced it (human | ai | campaign; null = typed on the phone)
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->unsignedBigInteger('sent_by')->nullable();
            $table->string('source', 10)->nullable();
        });

        // monthly targets of the team or of one person
        Schema::create('team_goals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('member_id')->default(0); // 0 = the whole team
            $table->string('metric', 30);                        // deals_won | won_value | answered | first_response_minutes
            $table->decimal('target', 14, 2);
            $table->timestamps();
            $table->unique(['user_id', 'member_id', 'metric']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_goals');
        Schema::table('chat_messages', fn (Blueprint $t) => $t->dropColumn(['sent_by', 'source']));
    }
};
