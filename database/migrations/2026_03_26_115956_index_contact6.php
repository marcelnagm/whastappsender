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
        DB::statement("
            DELETE c1 FROM contacts c1
            INNER JOIN contacts c2 ON c1.user_id = c2.user_id 
            WHERE c1.id < c2.id
        ");
        Schema::table('contacts', function (Blueprint $table) {

            $table->string('profile_url', 255)->nullable()->after('contact'); 
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
