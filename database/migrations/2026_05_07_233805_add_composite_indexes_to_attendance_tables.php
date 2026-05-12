<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add missing composite indexes identified in the attendance audit (3.9).
     *
     * Already present (no-op here):
     *   attendances        → (user_id, shift_date), status
     *   attendance_points  → (user_id, shift_date)
     *   biometric_records  → (user_id, record_date, record_time)
     *
     * Missing:
     *   break_sessions     → (user_id, shift_date, status)  for dashboard queries
     */
    public function up(): void
    {
        Schema::table('break_sessions', function (Blueprint $table) {
            $table->index(['user_id', 'shift_date', 'status'], 'break_sessions_user_shift_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('break_sessions', function (Blueprint $table) {
            $table->dropIndex('break_sessions_user_shift_status_index');
        });
    }
};
