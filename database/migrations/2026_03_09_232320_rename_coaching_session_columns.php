<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('coaching_sessions', function (Blueprint $table) {
            $table->dropForeign(['agent_id']);
            $table->dropForeign(['team_lead_id']);

            $table->renameColumn('agent_id', 'coachee_id');
            $table->renameColumn('team_lead_id', 'coach_id');
        });

        Schema::table('coaching_sessions', function (Blueprint $table) {
            $table->foreign('coachee_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('coach_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('coaching_sessions', function (Blueprint $table) {
            $table->dropForeign(['coachee_id']);
            $table->dropForeign(['coach_id']);

            $table->renameColumn('coachee_id', 'agent_id');
            $table->renameColumn('coach_id', 'team_lead_id');
        });

        Schema::table('coaching_sessions', function (Blueprint $table) {
            $table->foreign('agent_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('team_lead_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
