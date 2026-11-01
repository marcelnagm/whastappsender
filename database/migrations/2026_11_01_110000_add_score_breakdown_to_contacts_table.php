<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->json('score_breakdown')->nullable();                 // what the automatic score is made of
            $table->timestamp('score_updated_at')->nullable()->index();
            $table->boolean('score_locked')->default(false);             // a score typed by a person is never overwritten
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropIndex(['score_updated_at']);
            $table->dropColumn(['score_breakdown', 'score_updated_at', 'score_locked']);
        });
    }
};
