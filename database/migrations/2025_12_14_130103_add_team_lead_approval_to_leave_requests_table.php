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
        Schema::table('leave_requests', function (Blueprint $table) {
            // Team Lead approval tracking (for Agent leave requests)
            $table->boolean('requires_tl_approval')->default(false)->after('hr_review_notes');
            $table->foreignId('tl_approved_by')->nullable()->after('requires_tl_approval')->constrained('users')->nullOnDelete();
            $table->timestamp('tl_approved_at')->nullable()->after('tl_approved_by');
            $table->text('tl_review_notes')->nullable()->after('tl_approved_at');
            $table->boolean('tl_rejected')->default(false)->after('tl_review_notes');

            // Index for efficient queries
            $table->index(['requires_tl_approval', 'tl_approved_by']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropIndex(['requires_tl_approval', 'tl_approved_by']);
            $table->dropForeign(['tl_approved_by']);
            $table->dropColumn([
                'requires_tl_approval',
                'tl_approved_by',
                'tl_approved_at',
                'tl_review_notes',
                'tl_rejected',
            ]);
        });
    }
};
