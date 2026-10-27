<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::table('instances', function (Blueprint $table) {
            $table->timestamp('disconnected_at')->nullable();   // when the current outage started
            $table->timestamp('down_alerted_at')->nullable();   // when the owner was told about it
        });
    }

    public function down()
    {
        Schema::table('instances', function (Blueprint $table) {
            $table->dropColumn(['disconnected_at', 'down_alerted_at']);
        });
    }
};
