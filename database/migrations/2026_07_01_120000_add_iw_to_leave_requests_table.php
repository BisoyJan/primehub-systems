<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE leave_requests MODIFY COLUMN leave_type ENUM('VL', 'SL', 'BL', 'SPL', 'LOA', 'LDV', 'UPTO', 'ML', 'IW')");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE leave_requests MODIFY COLUMN leave_type ENUM('VL', 'SL', 'BL', 'SPL', 'LOA', 'LDV', 'UPTO', 'ML')");
    }
};
