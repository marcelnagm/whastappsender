<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('deal_stage_entries', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('deal_id')->index();
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('pipeline_id');
            $t->unsignedBigInteger('stage_id');
            $t->timestamp('entered_at');
            $t->timestamp('left_at')->nullable();
            $t->index(['pipeline_id', 'entered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_stage_entries');
    }
};
