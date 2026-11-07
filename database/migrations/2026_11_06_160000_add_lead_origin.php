<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Where the lead came from: a short label to group the funnel report by ("Instagram", "Google / cpc / black-friday"...)
        Schema::table('deals', function (Blueprint $table) {
            $table->string('origin', 80)->nullable()->index();
        });
        // UTM parameters of the page where a contact form was filled in
        Schema::table('contact_form_submissions', function (Blueprint $table) {
            $table->json('utm')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('contact_form_submissions', fn (Blueprint $t) => $t->dropColumn('utm'));
        Schema::table('deals', fn (Blueprint $t) => $t->dropColumn('origin'));
    }
};
