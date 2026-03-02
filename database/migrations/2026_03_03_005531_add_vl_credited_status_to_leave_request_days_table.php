<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds 'vl_credited' to the day_status enum in leave_request_days table.
     * This enables per-day VL credit assignment (VL Credited vs UPTO),
     * mirroring the existing SL per-day status system.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE leave_request_days MODIFY COLUMN day_status ENUM('pending', 'sl_credited', 'ncns', 'advised_absence', 'vl_credited') NOT NULL DEFAULT 'pending'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // First update any vl_credited rows to pending so we don't lose data on rollback
        DB::table('leave_request_days')
            ->where('day_status', 'vl_credited')
            ->update(['day_status' => 'pending']);

        DB::statement("ALTER TABLE leave_request_days MODIFY COLUMN day_status ENUM('pending', 'sl_credited', 'ncns', 'advised_absence') NOT NULL DEFAULT 'pending'");
    }
};
