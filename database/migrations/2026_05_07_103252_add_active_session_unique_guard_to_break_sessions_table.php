<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds a DB-level guarantee that a single user cannot have two active/paused
     * break sessions for the same shift_date.
     *
     * MySQL doesn't support partial indexes, so we use a generated stored column
     * that holds (user_id, shift_date) only when status IN ('active','paused')
     * and NULL otherwise, then a UNIQUE index on it.
     */
    public function up(): void
    {
        $this->safeDropIndex('break_sessions', 'break_sessions_active_guard_unique');
        $this->safeDropColumn('break_sessions', 'active_session_guard');

        DB::statement(
            "ALTER TABLE break_sessions
             ADD COLUMN active_session_guard VARCHAR(40)
             GENERATED ALWAYS AS (
                CASE WHEN status IN ('active','paused')
                     THEN CONCAT(user_id, '_', shift_date)
                     ELSE NULL
                END
             ) VIRTUAL"
        );

        DB::statement(
            'ALTER TABLE break_sessions
             ADD UNIQUE INDEX break_sessions_active_guard_unique (active_session_guard)'
        );
    }

    public function down(): void
    {
        $this->safeDropIndex('break_sessions', 'break_sessions_active_guard_unique');
        $this->safeDropColumn('break_sessions', 'active_session_guard');
    }

    private function safeDropIndex(string $table, string $index): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $exists = collect(DB::select("SHOW INDEX FROM {$table} WHERE Key_name = ?", [$index]))->isNotEmpty();
        if ($exists) {
            DB::statement("ALTER TABLE {$table} DROP INDEX {$index}");
        }
    }

    private function safeDropColumn(string $table, string $column): void
    {
        if (Schema::hasColumn($table, $column)) {
            DB::statement("ALTER TABLE {$table} DROP COLUMN {$column}");
        }
    }
};
