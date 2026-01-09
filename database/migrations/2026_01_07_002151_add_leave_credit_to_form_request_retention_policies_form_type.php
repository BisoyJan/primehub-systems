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
        // Modify the enum to include 'leave_credit'
        DB::statement("ALTER TABLE form_request_retention_policies MODIFY COLUMN form_type ENUM('all', 'leave_request', 'it_concern', 'medication_request', 'leave_credit') DEFAULT 'all'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert back to the original enum values
        DB::statement("ALTER TABLE form_request_retention_policies MODIFY COLUMN form_type ENUM('all', 'leave_request', 'it_concern', 'medication_request') DEFAULT 'all'");
    }
};
