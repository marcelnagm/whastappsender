<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Canned answers the whole team can drop into a conversation by typing "/shortcut"
        Schema::create('quick_replies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();   // account
            $table->string('shortcut', 40);
            $table->string('title', 80);
            $table->text('body');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedInteger('uses')->default(0);
            $table->timestamps();
            $table->unique(['user_id', 'shortcut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quick_replies');
    }
};
