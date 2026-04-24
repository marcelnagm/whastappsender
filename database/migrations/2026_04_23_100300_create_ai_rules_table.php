<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('name');
            $table->unsignedInteger('priority')->default(100);
            $table->boolean('is_active')->default(true);
            $table->string('trigger_type', 40);
            $table->string('trigger_value')->nullable();
            $table->string('action', 40)->default('reply');
            $table->json('action_payload')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            $table->index(['user_id', 'is_active']);
            $table->index(['user_id', 'priority']);
            $table->index(['trigger_type', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_rules');
    }
};
