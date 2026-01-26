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
        Schema::table('attendances', function (Blueprint $table) {
            // Undertime approval workflow fields
            // Status: null (not applicable), pending, approved, rejected
            $table->string('undertime_approval_status')->nullable()->after('undertime_minutes');

            // Reason for undertime approval decision
            // Values: generate_points, skip_points, lunch_used
            $table->string('undertime_approval_reason')->nullable()->after('undertime_approval_status');

            // Who requested the undertime approval (usually Team Lead)
            $table->foreignId('undertime_approval_requested_by')
                ->nullable()
                ->after('undertime_approval_reason')
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('undertime_approval_requested_at')->nullable()->after('undertime_approval_requested_by');

            // Who approved/rejected the undertime (Super Admin/Admin/HR)
            $table->foreignId('undertime_approved_by')
                ->nullable()
                ->after('undertime_approval_requested_at')
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('undertime_approved_at')->nullable()->after('undertime_approved_by');

            // Notes from the approver
            $table->text('undertime_approval_notes')->nullable()->after('undertime_approved_at');

            // Index for querying pending approvals
            $table->index('undertime_approval_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropForeign(['undertime_approval_requested_by']);
            $table->dropForeign(['undertime_approved_by']);
            $table->dropIndex(['undertime_approval_status']);

            $table->dropColumn([
                'undertime_approval_status',
                'undertime_approval_reason',
                'undertime_approval_requested_by',
                'undertime_approval_requested_at',
                'undertime_approved_by',
                'undertime_approved_at',
                'undertime_approval_notes',
            ]);
        });
    }
};
