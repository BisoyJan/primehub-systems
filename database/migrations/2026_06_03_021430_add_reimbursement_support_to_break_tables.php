<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('break_sessions', function (Blueprint $table) {
            $table->unsignedInteger('reimbursed_seconds')->default(0)->after('overage_seconds');
        });

        DB::statement("ALTER TABLE break_events MODIFY COLUMN action ENUM('start', 'pause', 'resume', 'end', 'time_up', 'reset', 'auto_end', 'force_end', 'restore', 'reimburse')");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE break_events MODIFY COLUMN action ENUM('start', 'pause', 'resume', 'end', 'time_up', 'reset', 'auto_end', 'force_end', 'restore')");

        Schema::table('break_sessions', function (Blueprint $table) {
            $table->dropColumn('reimbursed_seconds');
        });
    }
};
