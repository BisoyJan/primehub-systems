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
        DB::statement("ALTER TABLE break_sessions MODIFY COLUMN status ENUM('active', 'paused', 'completed', 'overage', 'auto_ended', 'reset') DEFAULT 'active'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Convert any 'reset' sessions back to 'completed' before shrinking the ENUM
        DB::table('break_sessions')->where('status', 'reset')->update(['status' => 'completed']);
        DB::statement("ALTER TABLE break_sessions MODIFY COLUMN status ENUM('active', 'paused', 'completed', 'overage', 'auto_ended') DEFAULT 'active'");
    }
};
