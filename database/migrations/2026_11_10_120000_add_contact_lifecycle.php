<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $t) {
            $t->string('lifecycle_stage', 20)->nullable()->index();
            $t->timestamp('lifecycle_changed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('contacts', fn (Blueprint $t) => $t->dropColumn(['lifecycle_stage', 'lifecycle_changed_at']));
    }
};
