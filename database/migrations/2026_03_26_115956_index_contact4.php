<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

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

            $table->string('profile_url', 255)->nullable()->after('contact');
            $table->unique(['user_id', 'lid']);
            $table->unique(['user_id', 'contact']);
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
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropUnique('contacts_user_lid_unique');
            // O dropColumn é opcional dependendo da sua política de rollback
        });
    }
};
