<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 40)->unique();
            $table->string('name', 80);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->unsignedInteger('price_cents')->default(0);          // monthly
            $table->unsignedInteger('yearly_price_cents')->nullable();   // null = monthly only
            $table->string('currency', 3)->default('BRL');
            $table->unsignedSmallInteger('trial_days')->default(0);
            $table->json('features')->nullable();
            $table->json('limits')->nullable();
            $table->boolean('is_active')->default(true);                 // can be chosen by customers
            $table->json('gateway_ids')->nullable();                     // filled by the payment gateway integration
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('plan_id');
            // pending (paid plan waiting for the first payment), trialing, active, past_due (grace),
            // suspended (blocked), canceled, expired
            $table->string('status', 20)->default('pending')->index();
            $table->string('cycle', 10)->default('monthly');             // monthly | yearly
            $table->timestamp('started_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable()->index(); // renews / expires at
            $table->timestamp('grace_ends_at')->nullable();              // end of past_due
            $table->timestamp('suspended_at')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('canceled_at')->nullable();
            $table->timestamp('ends_at')->nullable();                    // when access ended (canceled / expired)
            $table->unsignedBigInteger('pending_plan_id')->nullable();   // downgrade applied at next renewal
            $table->string('pending_cycle', 10)->nullable();
            $table->string('gateway', 40)->nullable();
            $table->string('gateway_customer_id')->nullable();
            $table->string('gateway_subscription_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('subscription_id')->index();
            $table->unsignedBigInteger('plan_id');
            $table->string('number', 30)->unique();
            $table->string('status', 20)->default('open')->index();      // open, paid, void
            $table->string('cycle', 10)->default('monthly');
            $table->unsignedInteger('amount_cents')->default(0);
            $table->unsignedInteger('credit_cents')->default(0);          // proration credit already deducted
            $table->string('currency', 3)->default('BRL');
            $table->string('description')->nullable();
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->timestamp('due_at')->nullable()->index();
            $table->timestamp('paid_at')->nullable();
            $table->string('payment_method', 30)->nullable();             // pix, card, boleto, manual...
            $table->string('gateway', 40)->nullable();
            $table->string('gateway_invoice_id')->nullable()->index();
            $table->json('gateway_payload')->nullable();
            $table->timestamps();
        });

        Schema::create('subscription_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('subscription_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('type', 40);
            $table->json('data')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('usage_counters', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('metric', 40);
            $table->string('period', 7);                                  // YYYY-MM
            $table->unsignedBigInteger('used')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'metric', 'period']);
        });

        // Free + paid plans from config/billing.php (existing rows are never overwritten)
        \App\Models\Plan::syncFromConfig();
    }

    public function down()
    {
        Schema::dropIfExists('usage_counters');
        Schema::dropIfExists('subscription_events');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plans');
    }
};
