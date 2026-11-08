<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('pipeline_stages', function (Blueprint $t) {
            $t->json('checklist')->nullable();
            $t->boolean('checklist_required')->default(false);
        });
        Schema::create('deal_checks', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('deal_id');
            $t->unsignedBigInteger('stage_id');
            $t->string('item_hash', 32);
            $t->unsignedBigInteger('done_by')->nullable();
            $t->timestamps();
            $t->unique(['deal_id', 'stage_id', 'item_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_checks');
        Schema::table('pipeline_stages', fn (Blueprint $t) => $t->dropColumn(['checklist', 'checklist_required']));
    }
};
