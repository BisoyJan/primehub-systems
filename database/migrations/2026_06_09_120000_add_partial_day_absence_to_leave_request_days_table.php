<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE leave_request_days MODIFY COLUMN day_status ENUM('pending', 'sl_credited', 'ncns', 'advised_absence', 'vl_credited', 'upto', 'spl_credited', 'absent', 'partial_day_absence') NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        DB::statement("UPDATE leave_request_days SET day_status = 'advised_absence' WHERE day_status = 'partial_day_absence'");
        DB::statement("ALTER TABLE leave_request_days MODIFY COLUMN day_status ENUM('pending', 'sl_credited', 'ncns', 'advised_absence', 'vl_credited', 'upto', 'spl_credited', 'absent') NOT NULL DEFAULT 'pending'");
    }
};
