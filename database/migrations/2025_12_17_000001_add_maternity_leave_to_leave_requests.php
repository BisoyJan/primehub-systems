<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Adds ML (Maternity Leave) to the leave_type enum.
     */
    public function up(): void
    {
        // MySQL requires altering the column to modify enum values
        DB::statement("ALTER TABLE leave_requests MODIFY COLUMN leave_type ENUM('VL', 'SL', 'BL', 'SPL', 'LOA', 'LDV', 'UPTO', 'ML')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert to original enum values (only if no ML records exist)
        DB::statement("ALTER TABLE leave_requests MODIFY COLUMN leave_type ENUM('VL', 'SL', 'BL', 'SPL', 'LOA', 'LDV', 'UPTO')");
    }
};
