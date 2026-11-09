<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // every call to an AI provider made for the account: what it cost in tokens, who/what asked for it
        Schema::create('ai_usage', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->index();
            $t->string('kind', 12);                          // reply | insight
            $t->unsignedBigInteger('ai_profile_id')->nullable();
            $t->string('provider', 40);
            $t->string('model', 120)->nullable();
            $t->unsignedInteger('tokens_in')->nullable();
            $t->unsignedInteger('tokens_out')->nullable();
            $t->timestamp('created_at')->nullable()->index();
        });

        // 👍/👎 given by the team to an answer written by an AI agent
        Schema::table('chat_messages', function (Blueprint $t) {
            $t->smallInteger('ai_feedback')->nullable();     // 1 | -1
            $t->string('ai_feedback_note', 300)->nullable();
            $t->unsignedBigInteger('ai_feedback_by')->nullable();
        });

        // one automatic report per account, sent by e-mail and shown as a notification
        Schema::create('insight_schedules', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->unique();
            $t->boolean('enabled')->default(true);
            $t->string('frequency', 10)->default('weekly');  // weekly (Mondays) | monthly (day 1)
            $t->string('business_type', 20)->default('general');
            $t->unsignedSmallInteger('period_days')->default(30);
            $t->timestamp('last_run_at')->nullable();
            $t->string('last_error', 300)->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insight_schedules');
        Schema::table('chat_messages', fn (Blueprint $t) => $t->dropColumn(['ai_feedback', 'ai_feedback_note', 'ai_feedback_by']));
        Schema::dropIfExists('ai_usage');
    }
};
