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
        Schema::table('contacts', function (Blueprint $table) {            
            $table->string('status')->nullable()->default('ativo'); // Ex: 559598..
            $table->integer('score')->nullable()->default('0'); // Ex: 559598..                       
        });

        Schema::table('whatsapp_job', function (Blueprint $table) {            
            $table->dropColumn('payload')->nullable()->default('ativo'); // Ex: 559598..                   
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
