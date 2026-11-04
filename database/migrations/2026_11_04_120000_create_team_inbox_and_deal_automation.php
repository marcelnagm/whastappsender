<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // who is handling each conversation (the account owner or a team member)
        Schema::table('chats', function (Blueprint $table) {
            $table->unsignedBigInteger('assigned_to')->nullable()->index();
            $table->timestamp('assigned_at')->nullable();
        });

        // conversation labels
        Schema::create('chat_tags', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('name', 40);
            $table->string('color', 9)->default('#6c757d');
            $table->timestamps();
            $table->unique(['user_id', 'name']);
        });

        Schema::create('chat_tag_links', function (Blueprint $table) {
            $table->unsignedBigInteger('chat_id');
            $table->unsignedBigInteger('tag_id');
            $table->primary(['chat_id', 'tag_id']);
        });

        // internal notes: never sent to the customer
        Schema::create('chat_notes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();   // account
            $table->unsignedBigInteger('chat_id')->index();
            $table->unsignedBigInteger('author_id')->nullable(); // the person who wrote it
            $table->text('body');
            $table->timestamps();
        });

        // account settings (kept on the account owner)
        Schema::table('users', function (Blueprint $table) {
            $table->string('chat_assign_mode', 12)->default('manual');   // manual | balanced
            $table->string('chat_agents_see', 12)->default('all');       // all | assigned
            $table->boolean('deal_auto_chat')->default(false);           // open a deal when a new lead writes
            $table->boolean('deal_auto_form')->default(false);           // open a deal when a contact form arrives
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['chat_assign_mode', 'chat_agents_see', 'deal_auto_chat', 'deal_auto_form']);
        });
        Schema::dropIfExists('chat_notes');
        Schema::dropIfExists('chat_tag_links');
        Schema::dropIfExists('chat_tags');
        Schema::table('chats', function (Blueprint $table) {
            $table->dropColumn(['assigned_to', 'assigned_at']);
        });
    }
};
