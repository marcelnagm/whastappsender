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
        //
        Schema::table('whatsapp_job', function (Blueprint $table) {
            $table->unsignedBigInteger('campaign_id')->after('message_id');
            $table->foreign('campaign_id')->references('id')->on('campaign');
            $table->unsignedBigInteger('campaign_item_id')->after('campaign_id');
            $table->foreign('campaign_item_id')->references('id')->on('campaign_item');
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
