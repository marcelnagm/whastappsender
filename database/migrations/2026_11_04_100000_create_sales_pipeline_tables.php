<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Sales funnel: pipelines hold ordered stages, deals sit in a stage and may point to a contact / conversation
        Schema::create('pipelines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('name', 120);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('pipeline_stages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('pipeline_id')->index();
            $table->string('name', 80);
            $table->string('kind', 10)->default('open'); // open | won | lost
            $table->string('color', 9)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('deals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('pipeline_id')->index();
            $table->unsignedBigInteger('stage_id')->index();
            $table->unsignedBigInteger('contact_id')->nullable()->index();
            $table->unsignedBigInteger('chat_id')->nullable();
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->string('title', 160);
            $table->decimal('value', 12, 2)->default(0);
            $table->string('status', 10)->default('open')->index(); // open | won | lost
            $table->string('lost_reason', 255)->nullable();
            $table->date('expected_close_date')->nullable();
            $table->string('source', 40)->nullable(); // manual | form | chat ...
            $table->unsignedInteger('position')->default(0);
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deals');
        Schema::dropIfExists('pipeline_stages');
        Schema::dropIfExists('pipelines');
    }
};
