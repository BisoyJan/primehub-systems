<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add 'upto' to the day_status enum in leave_request_days table.
     * VL UPTO days use 'upto' (attendance → on_leave, no violation).
     * SL UPTO days keep 'advised_absence' (attendance → advised_absence).
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE leave_request_days MODIFY COLUMN day_status ENUM('pending', 'sl_credited', 'ncns', 'advised_absence', 'vl_credited', 'upto') NOT NULL DEFAULT 'pending'");

        // Migrate existing VL UPTO days from 'advised_absence' to 'upto'
        DB::statement("
            UPDATE leave_request_days lrd
            INNER JOIN leave_requests lr ON lrd.leave_request_id = lr.id
            SET lrd.day_status = 'upto'
            WHERE lrd.day_status = 'advised_absence'
            AND lr.leave_type = 'VL'
        ");
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        // Revert VL 'upto' back to 'advised_absence'
        DB::statement("
            UPDATE leave_request_days lrd
            INNER JOIN leave_requests lr ON lrd.leave_request_id = lr.id
            SET lrd.day_status = 'advised_absence'
            WHERE lrd.day_status = 'upto'
            AND lr.leave_type = 'VL'
        ");

        DB::statement("ALTER TABLE leave_request_days MODIFY COLUMN day_status ENUM('pending', 'sl_credited', 'ncns', 'advised_absence', 'vl_credited') NOT NULL DEFAULT 'pending'");
    }
};
