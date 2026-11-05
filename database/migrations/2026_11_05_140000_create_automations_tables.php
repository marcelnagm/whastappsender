<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // "When <trigger>, do <actions>": rules of the account that act on deals, conversations and tasks
        Schema::create('automations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('name', 80);
            $table->boolean('active')->default(true);
            $table->string('trigger', 30);           // deal.created | deal.stage | deal.won | deal.lost | tag.added | message.keyword
            $table->json('conditions')->nullable();  // {stage_id?, tag_id?, keywords?}
            $table->json('actions');                  // [{type, ...}]
            $table->unsignedInteger('runs')->default(0);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();
        });

        Schema::create('automation_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('automation_id')->index();
            $table->string('subject', 60)->nullable();   // "deal #12", "chat #7"
            $table->string('status', 10);                // ok | partial | error
            $table->text('detail')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_runs');
        Schema::dropIfExists('automations');
    }
};
