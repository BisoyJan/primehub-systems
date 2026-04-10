<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('coaching_sessions', function (Blueprint $table) {
            $table->boolean('is_draft')->default(false)->after('severity_flag');
            $table->timestamp('submitted_at')->nullable()->after('is_draft');

            $table->index('is_draft');
        });

        // Make fields nullable to support drafts
        // Enum columns require raw SQL to modify in MySQL
        DB::statement("ALTER TABLE coaching_sessions MODIFY COLUMN purpose ENUM('performance_behavior_issue','regular_checkin_progress_review','reinforce_positive_behavior_growth','recognition_appreciation') NULL");

        Schema::table('coaching_sessions', function (Blueprint $table) {
            $table->date('session_date')->nullable()->change();
            $table->text('performance_description')->nullable()->change();
            $table->text('smart_action_plan')->nullable()->change();
        });

        // Set submitted_at for existing non-draft records
        DB::statement('UPDATE coaching_sessions SET submitted_at = created_at WHERE is_draft = 0 AND submitted_at IS NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Restore non-nullable columns
        DB::statement("ALTER TABLE coaching_sessions MODIFY COLUMN purpose ENUM('performance_behavior_issue','regular_checkin_progress_review','reinforce_positive_behavior_growth','recognition_appreciation') NOT NULL");

        Schema::table('coaching_sessions', function (Blueprint $table) {
            $table->date('session_date')->nullable(false)->change();
            $table->text('performance_description')->nullable(false)->change();
            $table->text('smart_action_plan')->nullable(false)->change();
        });

        Schema::table('coaching_sessions', function (Blueprint $table) {
            $table->dropIndex(['is_draft']);
            $table->dropColumn(['is_draft', 'submitted_at']);
        });
    }
};
