<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE break_events MODIFY COLUMN action ENUM('start', 'pause', 'resume', 'end', 'time_up', 'reset', 'auto_end', 'force_end', 'restore')");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE break_events MODIFY COLUMN action ENUM('start', 'pause', 'resume', 'end', 'time_up', 'reset')");
    }
};
