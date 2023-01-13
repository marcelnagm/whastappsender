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
        Schema::create('campaign', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->unsignedBigInteger('user_id')->nullable(true);
            $table->foreign('user_id')->references('id')->on('users');
            $table->timestamps();
        });

        Schema::create('contacts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('contact');
            $table->unsignedBigInteger('user_id')->nullable(true);
            $table->foreign('user_id')->references('id')->on('users');
            $table->timestamps();
        });
        Schema::create('campaign_item', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->text('text');
            $table->unsignedBigInteger('user_id')->nullable(true);
            $table->unsignedBigInteger('campaign_id')->nullable(true);
            $table->foreign('user_id')->references('id')->on('users');
            $table->foreign('campaign_id')->references('id')->on('campaign');
            $table->timestamps();
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
