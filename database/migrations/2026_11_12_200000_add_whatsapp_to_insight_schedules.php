<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('insight_schedules', function (Blueprint $t) {
            $t->string('whatsapp_number', 20)->nullable();   // also send the summary to this WhatsApp number
        });
    }

    public function down(): void
    {
        Schema::table('insight_schedules', fn (Blueprint $t) => $t->dropColumn('whatsapp_number'));
    }
};
