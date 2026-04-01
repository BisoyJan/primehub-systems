<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Rename grace_period_minutes → grace_period_seconds (if not yet done)
        if (Schema::hasColumn('break_policies', 'grace_period_minutes')) {
            Schema::table('break_policies', function (Blueprint $table) {
                $table->renameColumn('grace_period_minutes', 'grace_period_seconds');
            });

            DB::table('break_policies')->update([
                'grace_period_seconds' => DB::raw('grace_period_seconds * 60'),
            ]);
        }

        // 2. Add combined_break_count column (if not yet done)
        if (! Schema::hasColumn('break_sessions', 'combined_break_count')) {
            Schema::table('break_sessions', function (Blueprint $table) {
                $table->unsignedTinyInteger('combined_break_count')->nullable()->after('type');
            });
        }

        // 3. Migrate existing combined_Nb_lunch rows → combined + combined_break_count
        DB::table('break_sessions')
            ->where('type', 'combined_1b_lunch')
            ->update(['type' => 'combined', 'combined_break_count' => 1]);

        DB::table('break_sessions')
            ->where('type', 'combined_2b_lunch')
            ->update(['type' => 'combined', 'combined_break_count' => 2]);

        // Set default combined_break_count=1 for existing 'combined' rows that lack it
        DB::table('break_sessions')
            ->where('type', 'combined')
            ->whereNull('combined_break_count')
            ->update(['combined_break_count' => 1]);

        // 4. Now safely simplify type ENUM
        DB::statement("ALTER TABLE break_sessions MODIFY COLUMN type ENUM('1st_break', '2nd_break', 'break', 'lunch', 'combined')");
    }

    public function down(): void
    {
        // Revert type ENUM
        DB::statement("ALTER TABLE break_sessions MODIFY COLUMN type ENUM('1st_break', '2nd_break', 'lunch', 'combined', 'combined_1b_lunch', 'combined_2b_lunch')");

        Schema::table('break_sessions', function (Blueprint $table) {
            $table->dropColumn('combined_break_count');
        });

        // Convert back to minutes and rename
        DB::table('break_policies')->update([
            'grace_period_seconds' => DB::raw('FLOOR(grace_period_seconds / 60)'),
        ]);

        Schema::table('break_policies', function (Blueprint $table) {
            $table->renameColumn('grace_period_seconds', 'grace_period_minutes');
        });
    }
};
