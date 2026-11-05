<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // The account's own fields on a contact (city, plan, birthday...), values kept in contacts.custom as JSON
        Schema::table('contacts', function (Blueprint $table) {
            $table->json('custom')->nullable();
        });

        Schema::create('contact_fields', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('key', 40);                 // [a-z0-9_]
            $table->string('label', 60);
            $table->string('type', 10)->default('text'); // text | number | date | select | boolean
            $table->json('options')->nullable();        // choices of a select
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['user_id', 'key']);
        });

        // Saved filters: a segment is a list of conditions on contact data, usable as the audience of a campaign
        Schema::create('contact_segments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('name', 80);
            $table->json('filters');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_segments');
        Schema::dropIfExists('contact_fields');
        Schema::table('contacts', fn (Blueprint $t) => $t->dropColumn('custom'));
    }
};
