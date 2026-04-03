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
        DB::statement("ALTER TABLE break_sessions MODIFY COLUMN type ENUM('1st_break', '2nd_break', 'break', 'lunch', 'combined', 'combined_break') NOT NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('break_sessions')->where('type', 'combined_break')->update(['type' => 'break']);
        DB::statement("ALTER TABLE break_sessions MODIFY COLUMN type ENUM('1st_break', '2nd_break', 'break', 'lunch', 'combined') NOT NULL");
    }
};
