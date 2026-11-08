<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();       // the account
            $table->unsignedBigInteger('actor_id')->nullable();   // the person (owner or member)
            $table->string('actor_name', 120)->nullable();
            $table->string('action', 80)->index();                // route name
            $table->string('method', 8);
            $table->string('path', 200);
            $table->string('subject', 40)->nullable();            // id in the URL, when there is one
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
