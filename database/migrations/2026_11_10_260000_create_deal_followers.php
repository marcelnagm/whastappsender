<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('deal_followers', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('deal_id');
            $t->unsignedBigInteger('user_id');
            $t->timestamps();
            $t->unique(['deal_id', 'user_id']);
            $t->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_followers');
    }
};
