<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('recurrence', 10)->nullable();            // daily | weekly | monthly
            $table->unsignedBigInteger('next_task_id')->nullable();  // the occurrence created when this one was completed
        });
    }

    public function down(): void
    {
        Schema::table('tasks', fn (Blueprint $table) => $table->dropColumn(['recurrence', 'next_task_id']));
    }
};
