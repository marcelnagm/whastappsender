<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('kb_articles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('title', 160);
            $table->text('body');
            $table->string('keywords', 255)->nullable();   // extra words that find it
            $table->boolean('active')->default(true);
            $table->unsignedInteger('uses')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_articles');
    }
};
