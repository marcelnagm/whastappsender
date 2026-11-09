<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('insight_reports', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->index();
            $t->string('business_type', 20);
            $t->unsignedSmallInteger('period_days');
            $t->string('model', 120)->nullable();
            $t->text('summary')->nullable();
            $t->json('blocks');        // validated blocks the page draws
            $t->json('data');          // the numbers the AI was given (what the report can be checked against)
            $t->string('status', 10)->default('ok');   // ok | fallback (the AI did not follow the format)
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insight_reports');
    }
};
