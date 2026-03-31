<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE break_sessions MODIFY COLUMN type ENUM('1st_break', '2nd_break', 'lunch', 'combined')");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE break_sessions MODIFY COLUMN type ENUM('1st_break', '2nd_break', 'lunch')");
    }
};
