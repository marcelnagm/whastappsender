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
        Schema::create('whatsapp_job', function (Blueprint $table) {
            $table->id();
            $table->string('endpoint'); // Ex: 559598..
            $table->enum('status', ['pendente', 'processado', 'erro'])->default('pendente');
            $table->json('payload')->nullable(); // O que foi enviado
            $table->json('resposta')->nullable(); // O que a Evolution devolveu
            $table->text('erro_mensagem')->nullable(); // Erro técnico caso ocorra            
            $table->unsignedBigInteger('user_id')->nullable(true);
            $table->foreign('user_id')->references('id')->on('users');
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
        Schema::dropIfExists('whatsapp_envios');
    }
};
