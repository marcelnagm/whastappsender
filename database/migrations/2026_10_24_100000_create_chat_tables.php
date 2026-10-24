<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('chats', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('instance_id')->index();
            $table->string('remote_jid', 120);
            $table->string('phone', 32)->nullable()->index();
            $table->unsignedBigInteger('contact_id')->nullable()->index();
            $table->string('name')->nullable();            // WhatsApp push name
            $table->text('avatar_url')->nullable();
            $table->timestamp('avatar_checked_at')->nullable();
            $table->unsignedInteger('unread_count')->default(0);
            $table->boolean('archived')->default(false);
            $table->unsignedBigInteger('last_message_id')->nullable();
            $table->string('last_message_type', 20)->nullable();
            $table->string('last_message_preview', 255)->nullable();
            $table->boolean('last_message_from_me')->default(false);
            $table->string('last_message_status', 20)->nullable();
            $table->timestamp('last_message_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['instance_id', 'remote_jid']);
        });

        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('chat_id');
            $table->unsignedBigInteger('instance_id');
            $table->unsignedBigInteger('user_id')->index();
            $table->string('message_id', 120)->nullable();   // WhatsApp message id (key.id)
            $table->string('client_id', 64)->nullable()->index(); // id created by the browser (optimistic UI)
            $table->boolean('from_me')->default(false);
            $table->string('type', 20)->default('text');     // text,image,video,audio,document,sticker,location,contact,unsupported
            $table->text('body')->nullable();                // text or media caption
            $table->string('mimetype', 120)->nullable();
            $table->string('file_name')->nullable();
            $table->string('media_path')->nullable();        // local cache (storage/app/...)
            $table->string('quoted_message_id', 120)->nullable();
            $table->text('quoted_preview')->nullable();
            $table->json('reactions')->nullable();           // { "me": "👍", "5595...@s.whatsapp.net": "❤️" }
            $table->string('status', 20)->default('pending'); // pending,sent,delivered,read,played,received,error
            $table->text('error')->nullable();
            $table->boolean('deleted')->default(false);
            $table->timestamp('message_timestamp')->nullable();
            $table->timestamps();

            $table->unique(['instance_id', 'message_id']);
            $table->index(['chat_id', 'id']);
            $table->index(['chat_id', 'updated_at']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chats');
    }
};
