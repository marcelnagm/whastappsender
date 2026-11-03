<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // E-mail channel: the user's own mailbox. SMTP sends, IMAP (optional) receives. Passwords are stored encrypted (model cast).
        Schema::table('instances', function (Blueprint $table) {
            if (Schema::hasColumn('instances', 'email_address')) {
                return;
            }
            $table->string('email_address', 190)->nullable()->index();
            $table->string('email_from_name', 120)->nullable();
            $table->string('smtp_host', 190)->nullable();
            $table->unsignedSmallInteger('smtp_port')->nullable();
            $table->string('smtp_encryption', 10)->nullable();   // ssl (implicit TLS, 465) | tls (STARTTLS, 587) | none
            $table->string('smtp_username', 190)->nullable();
            $table->text('smtp_password')->nullable();
            $table->string('imap_host', 190)->nullable();         // empty = send only
            $table->unsignedSmallInteger('imap_port')->nullable();
            $table->string('imap_encryption', 10)->nullable();
            $table->string('imap_username', 190)->nullable();
            $table->text('imap_password')->nullable();
            $table->unsignedBigInteger('email_last_uid')->default(0);
            $table->unsignedBigInteger('email_uidvalidity')->nullable();
            $table->timestamp('email_checked_at')->nullable();
            $table->string('email_error', 255)->nullable();
        });

        // thread data of an e-mail conversation: subject and Message-IDs used to answer inside the same thread
        Schema::table('chats', function (Blueprint $table) {
            if (Schema::hasColumn('chats', 'email_subject')) {
                return;
            }
            $table->string('email_subject', 255)->nullable();
            $table->text('email_refs')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->dropColumn(['email_subject', 'email_refs']);
        });
        Schema::table('instances', function (Blueprint $table) {
            $table->dropIndex(['email_address']);
            $table->dropColumn(['email_address', 'email_from_name', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_password',
                'imap_host', 'imap_port', 'imap_encryption', 'imap_username', 'imap_password', 'email_last_uid', 'email_uidvalidity', 'email_checked_at', 'email_error']);
        });
    }
};
