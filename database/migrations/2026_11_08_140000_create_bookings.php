<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('booking_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->boolean('enabled')->default(false);
            $table->string('slug', 40)->unique();
            $table->string('title', 120)->default('Meeting');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->default(30);
            $table->unsignedSmallInteger('min_notice_hours')->default(2);
            $table->unsignedSmallInteger('max_days')->default(30);
            $table->timestamps();
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->unsignedBigInteger('task_id')->nullable();
            $table->string('name', 120);
            $table->string('phone', 40);
            $table->string('email', 160)->nullable();
            $table->timestamp('starts_at')->index();
            $table->timestamp('ends_at');
            $table->string('status', 10)->default('confirmed');   // confirmed | canceled
            $table->string('token', 40)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('booking_settings');
    }
};
