<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('name', 160);
            $table->string('domain', 160)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('industry', 80)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::table('contacts', fn (Blueprint $t) => $t->unsignedBigInteger('company_id')->nullable()->index());
        Schema::table('deals', fn (Blueprint $t) => $t->unsignedBigInteger('company_id')->nullable()->index());
    }

    public function down(): void
    {
        Schema::table('deals', fn (Blueprint $t) => $t->dropColumn('company_id'));
        Schema::table('contacts', fn (Blueprint $t) => $t->dropColumn('company_id'));
        Schema::dropIfExists('companies');
    }
};
