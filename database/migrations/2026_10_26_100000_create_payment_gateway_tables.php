<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('payment_gateways', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 30)->unique();          // mercadopago
            $table->boolean('enabled')->default(false);
            $table->text('access_token')->nullable();           // encrypted (model cast)
            $table->string('public_key')->nullable();
            $table->text('webhook_secret')->nullable();         // encrypted (model cast)
            $table->json('settings')->nullable();               // methods, recurrence, pix expiry, descriptor
            $table->timestamps();
        });

        // Audit trail of every notification received from the gateway
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 30);
            $table->string('type', 60)->nullable();
            $table->string('resource_id', 80)->nullable()->index();
            $table->string('status', 20)->default('received');  // received, processed, ignored, failed, rejected
            $table->string('note')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->boolean('auto_renew')->default(false);       // recurrence: card auto-charge or automatic Pix per cycle
            $table->string('payment_method', 20)->nullable();    // card | pix (method chosen for the recurrence)
            $table->string('gateway_status', 30)->nullable();    // status of the gateway's recurring authorization
            $table->text('payer_document')->nullable();          // CPF/CNPJ, encrypted (model cast): needed to issue Pix automatically
        });
    }

    public function down()
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['auto_renew', 'payment_method', 'gateway_status', 'payer_document']);
        });
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('payment_gateways');
    }
};
