<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Drop unused reset_approval column (approval text already stored in break_events.reason).
        if (Schema::hasColumn('break_sessions', 'reset_approval')) {
            Schema::table('break_sessions', function (Blueprint $table) {
                $table->dropColumn('reset_approval');
            });
        }

        // 2. Add ended_by column (agent | admin | system) for clearer reporting.
        if (! Schema::hasColumn('break_sessions', 'ended_by')) {
            DB::statement("ALTER TABLE break_sessions ADD COLUMN ended_by ENUM('agent', 'admin', 'system') NULL AFTER status");
        }

        // 3. Add (shift_date, status) composite index for live dashboard queries.
        Schema::table('break_sessions', function (Blueprint $table) {
            $indexes = collect(DB::select('SHOW INDEX FROM break_sessions'))
                ->pluck('Key_name')
                ->unique()
                ->all();

            if (! in_array('break_sessions_shift_date_status_index', $indexes, true)) {
                $table->index(['shift_date', 'status'], 'break_sessions_shift_date_status_index');
            }
        });

        // 4. Backfill ended_by for existing rows.
        DB::statement("UPDATE break_sessions SET ended_by = 'agent' WHERE status IN ('completed', 'overage') AND ended_by IS NULL");
        DB::statement("UPDATE break_sessions SET ended_by = 'system' WHERE status = 'auto_ended' AND ended_by IS NULL");
        DB::statement("UPDATE break_sessions SET ended_by = 'admin' WHERE status = 'reset' AND ended_by IS NULL");
    }

    public function down(): void
    {
        Schema::table('break_sessions', function (Blueprint $table) {
            $indexes = collect(DB::select('SHOW INDEX FROM break_sessions'))
                ->pluck('Key_name')
                ->unique()
                ->all();

            if (in_array('break_sessions_shift_date_status_index', $indexes, true)) {
                $table->dropIndex('break_sessions_shift_date_status_index');
            }

            if (Schema::hasColumn('break_sessions', 'ended_by')) {
                $table->dropColumn('ended_by');
            }

            if (! Schema::hasColumn('break_sessions', 'reset_approval')) {
                $table->string('reset_approval')->nullable();
            }
        });
    }
};
