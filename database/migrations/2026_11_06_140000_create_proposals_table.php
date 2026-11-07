<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // A quote sent to a customer as a public link he can accept or decline
        Schema::create('proposals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('deal_id')->index();
            $table->unsignedInteger('number');                  // sequence per account
            $table->string('token', 40)->unique();              // the public link
            $table->string('title', 160);
            $table->text('intro')->nullable();
            $table->text('terms')->nullable();
            $table->json('items');                              // snapshot: [{name, unit_price, quantity, discount_pct, total}]
            $table->decimal('total', 14, 2)->default(0);
            $table->date('valid_until')->nullable();
            $table->string('status', 10)->default('draft');     // draft | sent | viewed | accepted | declined
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->string('answered_by', 120)->nullable();     // name typed by the customer
            $table->string('answered_ip', 64)->nullable();
            $table->string('decline_reason', 300)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proposals');
    }
};
