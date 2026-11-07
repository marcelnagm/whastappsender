<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('stale_deal_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->boolean('enabled')->default(false);
            $table->unsignedSmallInteger('days')->default(7);   // no activity for this long = stale
            $table->timestamps();
        });
        Schema::table('deals', fn (Blueprint $t) => $t->timestamp('stale_notified_at')->nullable());
    }

    public function down(): void
    {
        Schema::table('deals', fn (Blueprint $t) => $t->dropColumn('stale_notified_at'));
        Schema::dropIfExists('stale_deal_settings');
    }
};
