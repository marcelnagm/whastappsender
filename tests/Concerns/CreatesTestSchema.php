<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Minimal in-memory schema for the features under test.
 * The legacy migrations are not used on purpose: several rely on MySQL-only SQL and seed data.
 * The chat migrations are the real ones.
 */
trait CreatesTestSchema
{
    protected function createTestSchema(): void
    {
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('email')->nullable();
            $t->string('username')->nullable();
            $t->string('password')->nullable();
            $t->string('role')->nullable();
            $t->boolean('active')->default(true);
            $t->boolean('ai_enabled')->default(false);
            $t->string('locale', 10)->nullable();
            $t->rememberToken();
            $t->timestamps();
        });

        Schema::create('instances', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('name')->nullable();
            $t->string('instance_name')->unique();
            $t->string('status')->default('disconnected');
            $t->boolean('warmup')->default(false);
            $t->timestamps();
        });

        Schema::create('contacts', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('contact')->nullable();
            $t->string('email')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->boolean('ignore_me')->default(false);
            $t->string('lid')->nullable();
            $t->string('status')->nullable();
            $t->integer('score')->nullable();
            $t->string('profile_url')->nullable();
            $t->timestamps();
        });

        Schema::create('whatsapp_job', function (Blueprint $t) {
            $t->id();
            $t->string('endpoint')->default('');
            $t->string('status')->default('pendente');
            $t->json('payload')->nullable();
            $t->json('resposta')->nullable();
            $t->string('message_id')->nullable();
            $t->string('evolution_status')->nullable();
            $t->text('erro_mensagem')->nullable();
            $t->unsignedInteger('tentativas')->default(0);
            $t->unsignedBigInteger('user_id')->nullable();
            $t->unsignedBigInteger('contact_id')->nullable();
            $t->unsignedBigInteger('instance_id')->nullable();
            $t->unsignedBigInteger('campaign_id')->nullable();
            $t->unsignedBigInteger('campaign_item_id')->nullable();
            $t->timestamps();
        });

        Schema::create('campaign_item', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->text('text')->nullable();
            $t->string('image')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->unsignedBigInteger('campaign_id')->nullable();
            $t->boolean('welcome_enabled')->default(false);
            $t->timestamps();
        });

        Schema::create('notifications', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('type');
            $t->morphs('notifiable');
            $t->text('data');
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
        });

        foreach ([
            '2026_10_24_100000_create_chat_tables.php',
            '2026_10_24_120000_add_avatar_cache_to_chats_table.php',
            '2026_10_25_100000_create_billing_tables.php',
        ] as $file) {
            (require database_path('migrations/' . $file))->up();
        }
    }
}
