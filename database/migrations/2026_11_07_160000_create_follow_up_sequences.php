<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sequences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('name', 120);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('sequence_steps', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sequence_id')->index();
            $table->unsignedSmallInteger('position');
            $table->unsignedInteger('delay_minutes');          // after the previous step (or after enrolling)
            $table->text('body');
            $table->timestamps();
        });

        Schema::create('sequence_enrollments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('sequence_id')->index();
            $table->unsignedBigInteger('chat_id')->index();
            $table->unsignedBigInteger('enrolled_by')->nullable();
            $table->unsignedSmallInteger('next_position')->default(1);
            $table->timestamp('next_at')->nullable();
            $table->string('status', 10)->default('active');  // active | completed | stopped
            $table->string('stop_reason', 40)->nullable();    // replied | manual | window | opt_out | failed | removed
            $table->timestamps();
            $table->index(['status', 'next_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sequence_enrollments');
        Schema::dropIfExists('sequence_steps');
        Schema::dropIfExists('sequences');
    }
};
