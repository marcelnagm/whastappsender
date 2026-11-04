<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Follow-up tasks: something a person must do for a contact / conversation / deal, with a due time and a reminder
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();        // account
            $table->string('title', 160);
            $table->text('notes')->nullable();
            $table->timestamp('due_at')->nullable()->index();
            $table->unsignedBigInteger('assigned_to')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('contact_id')->nullable()->index();
            $table->unsignedBigInteger('chat_id')->nullable()->index();
            $table->unsignedBigInteger('deal_id')->nullable()->index();
            $table->timestamp('done_at')->nullable();
            $table->unsignedBigInteger('done_by')->nullable();
            $table->timestamp('reminded_at')->nullable();           // the reminder went out (once per due time)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
