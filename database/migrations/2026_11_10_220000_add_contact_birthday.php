<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $t) {
            $t->date('birthday')->nullable();
            $t->unsignedSmallInteger('birthday_notified_year')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('contacts', fn (Blueprint $t) => $t->dropColumn(['birthday', 'birthday_notified_year']));
    }
};
