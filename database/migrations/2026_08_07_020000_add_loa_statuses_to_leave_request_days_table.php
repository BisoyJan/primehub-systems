<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE leave_request_days MODIFY COLUMN day_status ENUM('pending', 'sl_credited', 'ncns', 'advised_absence', 'vl_credited', 'upto', 'spl_credited', 'absent', 'partial_day_absence', 'loa_credited', 'loa_unpaid') NOT NULL DEFAULT 'pending'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("UPDATE leave_request_days SET day_status = 'upto' WHERE day_status IN ('loa_credited', 'loa_unpaid')");
        DB::statement("ALTER TABLE leave_request_days MODIFY COLUMN day_status ENUM('pending', 'sl_credited', 'ncns', 'advised_absence', 'vl_credited', 'upto', 'spl_credited', 'absent', 'partial_day_absence') NOT NULL DEFAULT 'pending'");
    }
};
