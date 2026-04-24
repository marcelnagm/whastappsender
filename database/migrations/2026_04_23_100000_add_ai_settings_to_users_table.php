<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('ai_enabled')->default(false)->after('active');
            $table->string('ai_mode', 20)->default('off')->after('ai_enabled');
            $table->string('ai_model')->nullable()->after('ai_mode');
            $table->decimal('ai_temperature', 3, 2)->default(0.70)->after('ai_model');
            $table->unsignedInteger('ai_max_tokens')->default(1024)->after('ai_temperature');
            $table->text('ai_system_prompt')->nullable()->after('ai_max_tokens');
            $table->boolean('ai_business_hours_only')->default(false)->after('ai_system_prompt');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'ai_enabled',
                'ai_mode',
                'ai_model',
                'ai_temperature',
                'ai_max_tokens',
                'ai_system_prompt',
                'ai_business_hours_only',
            ]);
        });
    }
};
