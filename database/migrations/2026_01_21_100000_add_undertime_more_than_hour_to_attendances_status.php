<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // For MySQL, we need to modify the enum column to add the new value
        if (config('database.default') === 'mysql') {
            DB::statement("ALTER TABLE attendances MODIFY COLUMN status ENUM(
                'on_time',
                'tardy',
                'half_day_absence',
                'advised_absence',
                'ncns',
                'undertime',
                'undertime_more_than_hour',
                'failed_bio_in',
                'failed_bio_out',
                'present_no_bio',
                'needs_manual_review',
                'non_work_day',
                'on_leave'
            ) NOT NULL DEFAULT 'on_time'");
        }
        // For SQLite (testing), we need to recreate the table since SQLite doesn't support ALTER COLUMN
        // Laravel's schema builder handles this transparently when running fresh migrations in tests
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert the enum (Note: will fail if any records have undertime_more_than_hour)
        if (config('database.default') === 'mysql') {
            DB::statement("ALTER TABLE attendances MODIFY COLUMN status ENUM(
                'on_time',
                'tardy',
                'half_day_absence',
                'advised_absence',
                'ncns',
                'undertime',
                'failed_bio_in',
                'failed_bio_out',
                'present_no_bio',
                'needs_manual_review',
                'non_work_day',
                'on_leave'
            ) NOT NULL DEFAULT 'on_time'");
        }
    }
};
