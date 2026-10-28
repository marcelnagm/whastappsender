<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One row = one AI agent: which provider/model, the prompt and which WhatsApp number it answers for
 * (instance_id NULL = any number of the user). Replaces the single set of ai_* columns of the users table.
 */
return new class extends Migration {
    public function up()
    {
        Schema::create('ai_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('instance_id')->nullable();   // NULL = any instance
            $table->string('name', 80);
            $table->boolean('enabled')->default(true);
            $table->string('provider', 30)->default('groq');
            $table->string('model', 120);
            $table->text('api_key')->nullable();                     // encrypted by the model
            $table->string('base_url', 255)->nullable();             // ollama / custom
            $table->decimal('temperature', 3, 2)->default(0.70);
            $table->unsignedInteger('max_tokens')->default(1024);
            $table->longText('system_prompt')->nullable();
            $table->boolean('business_hours_only')->default(false);
            $table->string('business_days', 20)->nullable();         // "1,2,3,4,5" (ISO, 1=Mon..7=Sun); NULL = Mon-Fri
            $table->string('business_start', 5)->nullable();         // "09:00"; NULL = config default
            $table->string('business_end', 5)->nullable();           // "18:00"
            $table->string('timezone', 64)->nullable();              // NULL = config default
            $table->timestamps();

            $table->index(['user_id', 'instance_id']);
        });

        // Keep what users already configured: their single agent becomes the "any instance" agent (Groq, platform key)
        if (Schema::hasColumn('users', 'ai_system_prompt')) {
            DB::table('users')->where(function ($q) {
                $q->where('ai_enabled', true)->orWhereNotNull('ai_system_prompt');
            })->orderBy('id')->each(function ($user) {
                DB::table('ai_profiles')->insert([
                    'user_id' => $user->id,
                    'instance_id' => null,
                    'name' => 'Default agent',
                    'enabled' => (bool) $user->ai_enabled,
                    'provider' => 'groq',
                    'model' => $user->ai_model ?: 'llama-3.3-70b-versatile',
                    'temperature' => $user->ai_temperature ?? 0.7,
                    'max_tokens' => $user->ai_max_tokens ?? 1024,
                    'system_prompt' => $user->ai_system_prompt,
                    'business_hours_only' => (bool) ($user->ai_business_hours_only ?? false),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('ai_profiles');
    }
};
