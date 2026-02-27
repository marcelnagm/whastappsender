<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('whatsapp_job', function (Blueprint $table) {
            // O message_id deve ser indexado para o Webhook achar o registro instantaneamente
            $table->string('message_id')->nullable()->index()->after('id');
            // Status específico da Evolution (sent, delivered, read)
            $table->string('evolution_status')->nullable()->after('status');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        //
    }
};
