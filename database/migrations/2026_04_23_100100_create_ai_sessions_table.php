<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('instance_id')->nullable();
            $table->unsignedBigInteger('contact_id');
            $table->string('status', 20)->default('active');
            $table->boolean('human_handoff')->default(false);
            $table->timestamp('last_inbound_at')->nullable();
            $table->timestamp('last_outbound_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('instance_id')->references('id')->on('instances')->nullOnDelete();
            $table->foreign('contact_id')->references('id')->on('contacts')->onDelete('cascade');

            $table->index(['user_id', 'contact_id']);
            $table->index(['instance_id', 'status']);
            $table->index('last_inbound_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_sessions');
    }
};
