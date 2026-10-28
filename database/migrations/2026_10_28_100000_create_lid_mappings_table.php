<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp resolves phone -> LID but generally not LID -> phone, so every pair we learn (message key, echo of a message
 * we sent, CRM contact, manual link) is kept for good: the next message of that LID lands in the right chat.
 */
return new class extends Migration {
    public function up()
    {
        Schema::create('lid_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('lid', 64)->unique();      // "123456789@lid"
            $table->string('phone', 20)->index();     // digits only
            $table->string('source', 20)->default('message');   // message | echo | contact | evolution | manual
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('lid_mappings');
    }
};
