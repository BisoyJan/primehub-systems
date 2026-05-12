<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Default the per-schedule tardy grace window to 0 minutes.
     *
     * Previously the column defaulted to 15, which silently granted every
     * employee a 15-minute "free" buffer before being marked tardy. Per
     * business decision, anyone 1+ minute late is tardy unless an admin
     * explicitly raises grace_period_minutes for a specific schedule.
     */
    public function up(): void
    {
        Schema::table('employee_schedules', function (Blueprint $table) {
            $table->integer('grace_period_minutes')->default(0)->change();
        });

        // Reset every existing row so the new policy applies retroactively.
        DB::table('employee_schedules')->update(['grace_period_minutes' => 0]);
    }

    public function down(): void
    {
        Schema::table('employee_schedules', function (Blueprint $table) {
            $table->integer('grace_period_minutes')->default(15)->change();
        });

        DB::table('employee_schedules')->update(['grace_period_minutes' => 15]);
    }
};
