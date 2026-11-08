<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $t) {
            $t->string('billing_cycle', 10)->nullable();   // monthly | quarterly | yearly: the deal is a subscription
            $t->date('renews_at')->nullable()->index();
            $t->timestamp('renewal_notified_at')->nullable();
            $t->timestamp('churned_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('deals', fn (Blueprint $t) => $t->dropColumn(['billing_cycle', 'renews_at', 'renewal_notified_at', 'churned_at']));
    }
};
