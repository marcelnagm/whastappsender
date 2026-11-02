<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Embeddable contact forms: the owner configures the fields, pastes one <script> in any site and every submission lands in the CRM
        Schema::create('contact_forms', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('name', 120);
            $table->string('public_key', 40)->unique();
            $table->string('title', 160)->nullable();
            $table->text('description')->nullable();
            $table->json('fields');
            $table->string('submit_label', 60)->nullable();
            $table->string('success_message', 500)->nullable();
            $table->string('redirect_url', 500)->nullable();
            $table->string('accent_color', 9)->nullable();
            $table->text('allowed_origins')->nullable();          // one domain per line; empty = any site
            $table->unsignedBigInteger('instance_id')->nullable(); // WhatsApp number that answers; empty = any connected one
            $table->json('reply_channels')->nullable();           // channels the AI may answer through; empty = all
            $table->boolean('ai_reply')->default(true);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('submissions_count')->default(0);
            $table->timestamps();
        });

        Schema::create('contact_form_submissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('contact_form_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->unsignedBigInteger('chat_id')->nullable();
            $table->json('data');
            $table->string('origin', 255)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('delivery', 20)->nullable();           // channel the conversation went to; "none" = stored only
            $table->boolean('ai_requested')->default(false);
            $table->string('status', 20)->default('pending');     // pending | processed | failed
            $table->string('note', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_form_submissions');
        Schema::dropIfExists('contact_forms');
    }
};
