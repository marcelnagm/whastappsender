<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('outbound_webhooks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('name', 80);
            $table->string('url', 500);
            $table->string('secret', 64);
            $table->text('events');                              // JSON list of event names
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('failures')->default(0); // consecutive failed deliveries; disables at the limit
            $table->timestamp('last_delivery_at')->nullable();
            $table->timestamps();
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('webhook_id')->index();
            $table->string('event', 40);
            $table->string('uuid', 40);
            $table->text('payload');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->boolean('ok')->default(false);
            $table->string('error', 200)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('outbound_webhooks');
    }
};
