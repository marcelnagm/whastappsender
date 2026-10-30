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
            $t->string('ai_mode')->default('off');
            $t->string('ai_model')->nullable();
            $t->decimal('ai_temperature', 3, 2)->nullable();
            $t->unsignedInteger('ai_max_tokens')->nullable();
            $t->text('ai_system_prompt')->nullable();
            $t->boolean('ai_business_hours_only')->default(false);
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
            $t->string('channel', 20)->default('whatsapp');
            $t->text('telegram_token')->nullable();
            $t->string('telegram_username', 64)->nullable();
            $t->string('telegram_secret', 64)->nullable();
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
            '2026_10_26_100000_create_payment_gateway_tables.php',
            '2026_10_27_100000_add_health_columns_to_instances_table.php',
            '2026_10_28_100000_create_lid_mappings_table.php',
            '2026_10_29_100000_add_chat_signature_to_users_table.php',
            '2026_04_23_100100_create_ai_sessions_table.php',
            '2026_04_23_100200_create_ai_messages_table.php',
            '2026_10_30_100000_create_ai_profiles_table.php',
        ] as $file) {
            (require database_path('migrations/' . $file))->up();
        }
    }
}
