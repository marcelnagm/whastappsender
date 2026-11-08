<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('activities', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->index();
            $t->unsignedBigInteger('contact_id')->index();
            $t->unsignedBigInteger('deal_id')->nullable()->index();
            $t->string('type', 20);
            $t->string('outcome', 120)->nullable();
            $t->text('notes')->nullable();
            $t->unsignedSmallInteger('duration_min')->nullable();
            $t->timestamp('occurred_at');
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activities');
    }
};
