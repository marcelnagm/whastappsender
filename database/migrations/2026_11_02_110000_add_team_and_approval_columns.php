<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Team members ("sub-users"): a user row that works for the account owner (the contract user)
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('parent_user_id')->nullable()->index()->after('role');
            $table->string('team_role', 20)->nullable()->after('parent_user_id');
        });

        // Approval flow and chosen audience of a campaign message
        Schema::table('campaign_item', function (Blueprint $table) {
            $table->string('approval_status', 20)->nullable()->index();   // null = never submitted (legacy / direct launch)
            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('approval_note')->nullable();
            $table->json('audience')->nullable();                          // {tiers[], min_score, max_score, channels[]}
            $table->unsignedInteger('audience_count')->nullable();
            $table->timestamp('launched_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('campaign_item', function (Blueprint $table) {
            $table->dropColumn(['approval_status', 'submitted_by', 'approved_by', 'approved_at', 'approval_note', 'audience', 'audience_count', 'launched_at']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['parent_user_id', 'team_role']);
        });
    }
};
