<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('biometric_retention_policies', function (Blueprint $table) {
            // Add record_type column to support different record types
            // 'all' = applies to all record types (biometric_record, attendance_point)
            // 'biometric_record' = only biometric/scan records
            // 'attendance_point' = only attendance points
            $table->enum('record_type', ['all', 'biometric_record', 'attendance_point'])
                ->default('all')
                ->after('applies_to_id');

            // Update index for better query performance
            $table->index(['is_active', 'record_type', 'priority'], 'brp_active_type_priority_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('biometric_retention_policies', function (Blueprint $table) {
            $table->dropIndex('brp_active_type_priority_idx');
            $table->dropColumn('record_type');
        });
    }
};
