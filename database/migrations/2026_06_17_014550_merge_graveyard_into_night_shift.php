<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Collapse graveyard_shift into night_shift.
     *
     * Frontend now derives shift_type from scheduled_time_in, so a separate
     * graveyard bucket adds no information — AttendanceProcessor already
     * detects "starts before 05:00" via isGraveyardShift() at runtime.
     */
    public function up(): void
    {
        DB::table('employee_schedules')
            ->where('shift_type', 'graveyard_shift')
            ->update(['shift_type' => 'night_shift']);

        DB::statement(
            "ALTER TABLE employee_schedules MODIFY COLUMN shift_type
             ENUM('night_shift','morning_shift','afternoon_shift','utility_24h')
             NOT NULL DEFAULT 'night_shift'"
        );
    }

    public function down(): void
    {
        DB::statement(
            "ALTER TABLE employee_schedules MODIFY COLUMN shift_type
             ENUM('night_shift','morning_shift','afternoon_shift','graveyard_shift','utility_24h')
             NOT NULL DEFAULT 'night_shift'"
        );
    }
};
