<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // The account's catalog of what it sells
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('name', 120);
            $table->string('sku', 40)->nullable();
            $table->string('unit', 20)->nullable();           // "un", "h", "month"...
            $table->decimal('price', 12, 2)->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        // What a deal sells. name / unit_price are a snapshot: editing the catalog later never rewrites a past deal.
        Schema::create('deal_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('deal_id')->index();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('name', 120);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('discount_pct', 5, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_items');
        Schema::dropIfExists('products');
    }
};
