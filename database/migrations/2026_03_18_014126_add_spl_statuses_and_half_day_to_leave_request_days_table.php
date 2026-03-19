<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds 'spl_credited' and 'absent' to the day_status enum in leave_request_days table.
     * Also adds 'is_half_day' boolean column for SPL half-day tracking.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE leave_request_days MODIFY COLUMN day_status ENUM('pending', 'sl_credited', 'ncns', 'advised_absence', 'vl_credited', 'upto', 'spl_credited', 'absent') NOT NULL DEFAULT 'pending'");

        Schema::table('leave_request_days', function (Blueprint $table) {
            $table->boolean('is_half_day')->default(false)->after('day_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leave_request_days', function (Blueprint $table) {
            $table->dropColumn('is_half_day');
        });

        DB::table('leave_request_days')
            ->whereIn('day_status', ['spl_credited', 'absent'])
            ->update(['day_status' => 'pending']);

        DB::statement("ALTER TABLE leave_request_days MODIFY COLUMN day_status ENUM('pending', 'sl_credited', 'ncns', 'advised_absence', 'vl_credited', 'upto') NOT NULL DEFAULT 'pending'");
    }
};
