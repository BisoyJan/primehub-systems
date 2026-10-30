<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds tracking fields for Super Admin reverting a denied leave request
     * back to pending. The reverted_denial_* columns snapshot the denial that
     * was undone so re-reviewers can see the original rationale.
     */
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->foreignId('reverted_by')->nullable()->constrained('users')->nullOnDelete()->after('cancellation_reason');
            $table->timestamp('reverted_at')->nullable()->after('reverted_by');
            $table->text('revert_reason')->nullable()->after('reverted_at');
            $table->foreignId('reverted_denial_by')->nullable()->constrained('users')->nullOnDelete()->after('revert_reason');
            $table->text('reverted_denial_notes')->nullable()->after('reverted_denial_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropForeign(['reverted_by']);
            $table->dropForeign(['reverted_denial_by']);

            $table->dropColumn([
                'reverted_by',
                'reverted_at',
                'revert_reason',
                'reverted_denial_by',
                'reverted_denial_notes',
            ]);
        });
    }
};
