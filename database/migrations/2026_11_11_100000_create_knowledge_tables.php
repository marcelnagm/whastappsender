<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('knowledge_sources', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->index();
            $t->string('type', 12);                  // faq | text | file | kb
            $t->string('title', 200);
            $t->string('uri', 255)->nullable();      // kb:{article id}, original file name...
            $t->longText('content')->nullable();     // the text that is indexed (kept so a re-index needs no upload)
            $t->string('status', 12)->default('pending');   // pending | indexing | ready | error
            $t->string('error', 255)->nullable();
            $t->unsignedInteger('chunks_count')->default(0);
            $t->string('embedder', 80)->nullable();
            $t->timestamp('last_indexed_at')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'uri']);
        });
        Schema::create('knowledge_chunks', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('source_id')->index();
            $t->unsignedBigInteger('user_id');
            $t->unsignedInteger('position')->default(0);
            $t->text('content');
            $t->longText('embedding');              // JSON array of floats, L2-normalised
            $t->string('embedder', 80);
            $t->timestamps();
            $t->index(['user_id', 'embedder']);
        });
        Schema::create('knowledge_questions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('question', 500);
            $t->float('best_score')->nullable();
            $t->unsignedInteger('hits')->default(1);
            $t->timestamps();
            $t->index(['user_id', 'updated_at']);
        });
        Schema::table('ai_profiles', function (Blueprint $t) {
            $t->boolean('use_knowledge')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('ai_profiles', fn (Blueprint $t) => $t->dropColumn('use_knowledge'));
        Schema::dropIfExists('knowledge_questions');
        Schema::dropIfExists('knowledge_chunks');
        Schema::dropIfExists('knowledge_sources');
    }
};
