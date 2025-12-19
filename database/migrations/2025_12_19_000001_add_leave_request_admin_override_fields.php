<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds fields for:
     * 1. Short notice override (Admin/Super Admin can bypass 2-week notice)
     * 2. Date modification tracking (when approved leave dates are changed)
     * 3. Auto-cancellation tracking (when employee reports to work during leave)
     */
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            // Short notice override fields (Scenario 2)
            $table->boolean('short_notice_override')->default(false)->after('auto_rejection_reason');
            $table->foreignId('short_notice_override_by')->nullable()->constrained('users')->nullOnDelete()->after('short_notice_override');
            $table->timestamp('short_notice_override_at')->nullable()->after('short_notice_override_by');

            // Date modification tracking fields (Scenario 1)
            $table->date('original_start_date')->nullable()->after('short_notice_override_at');
            $table->date('original_end_date')->nullable()->after('original_start_date');
            $table->foreignId('date_modified_by')->nullable()->constrained('users')->nullOnDelete()->after('original_end_date');
            $table->timestamp('date_modified_at')->nullable()->after('date_modified_by');
            $table->text('date_modification_reason')->nullable()->after('date_modified_at');

            // Auto-cancellation fields (Scenario 3)
            $table->boolean('auto_cancelled')->default(false)->after('date_modification_reason');
            $table->text('auto_cancelled_reason')->nullable()->after('auto_cancelled');
            $table->timestamp('auto_cancelled_at')->nullable()->after('auto_cancelled_reason');

            // Cancelled by tracking (Scenario 4 - for admin cancellation)
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete()->after('auto_cancelled_at');
            $table->timestamp('cancelled_at')->nullable()->after('cancelled_by');
            $table->text('cancellation_reason')->nullable()->after('cancelled_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            // Drop foreign keys first
            $table->dropForeign(['short_notice_override_by']);
            $table->dropForeign(['date_modified_by']);
            $table->dropForeign(['cancelled_by']);

            // Drop columns
            $table->dropColumn([
                'short_notice_override',
                'short_notice_override_by',
                'short_notice_override_at',
                'original_start_date',
                'original_end_date',
                'date_modified_by',
                'date_modified_at',
                'date_modification_reason',
                'auto_cancelled',
                'auto_cancelled_reason',
                'auto_cancelled_at',
                'cancelled_by',
                'cancelled_at',
                'cancellation_reason',
            ]);
        });
    }
};
