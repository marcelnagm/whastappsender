<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Groups the user marked "these are different people", so they stop being suggested
        Schema::create('duplicate_ignores', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('group_key', 120);
            $table->timestamps();
            $table->unique(['user_id', 'group_key']);
        });

        // What each merge removed, for audit
        Schema::create('contact_merges', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('kept_id');
            $table->unsignedBigInteger('dropped_id');
            $table->json('snapshot');
            $table->unsignedBigInteger('merged_by')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_merges');
        Schema::dropIfExists('duplicate_ignores');
    }
};
