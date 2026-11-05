<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // where each contact stands: unknown (no record) | granted | revoked
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('consent_status', 10)->default('unknown')->index();
            $table->string('consent_source', 40)->nullable();
            $table->timestamp('consent_at')->nullable();
            $table->timestamp('consent_revoked_at')->nullable();
        });

        // the proof: every grant / revoke with where it came from
        Schema::create('consent_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();      // account
            $table->unsignedBigInteger('contact_id')->index();
            $table->string('action', 10);                         // granted | revoked
            $table->string('source', 40);                         // form | keyword | manual | import | api
            $table->text('evidence')->nullable();                 // text accepted, keyword typed, note...
            $table->string('ip', 45)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();   // person who recorded it, when manual
            $table->timestamp('created_at')->nullable();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('consent_required')->default(false);  // campaigns only reach contacts with consent
        });

        Schema::table('contact_forms', function (Blueprint $table) {
            $table->string('consent_text', 300)->nullable();      // when set, the form shows a required checkbox with this text
        });

        Schema::table('contact_form_submissions', function (Blueprint $table) {
            $table->text('consent_text')->nullable();             // exactly what the person accepted
        });

        // people who already opted out keep that status
        DB::table('contacts')->where('ignore_me', 1)->update(['consent_status' => 'revoked', 'consent_source' => 'keyword']);
    }

    public function down(): void
    {
        Schema::table('contact_form_submissions', fn (Blueprint $t) => $t->dropColumn('consent_text'));
        Schema::table('contact_forms', fn (Blueprint $t) => $t->dropColumn('consent_text'));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('consent_required'));
        Schema::dropIfExists('consent_logs');
        Schema::table('contacts', fn (Blueprint $t) => $t->dropColumn(['consent_status', 'consent_source', 'consent_at', 'consent_revoked_at']));
    }
};
