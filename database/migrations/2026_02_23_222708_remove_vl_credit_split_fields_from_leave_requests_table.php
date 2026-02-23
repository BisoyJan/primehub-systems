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
     * Removes the VL credit split/UPTO conversion feature:
     * 1. Delete companion UPTO records (where linked_request_id IS NOT NULL)
     * 2. Drop the linked_request_id, vl_credits_applied, vl_no_credit_reason columns
     */
    public function up(): void
    {
        // Delete companion UPTO requests that were auto-created from VL splits
        DB::table('leave_requests')
            ->whereNotNull('linked_request_id')
            ->delete();

        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropForeign(['linked_request_id']);
            $table->dropColumn([
                'linked_request_id',
                'vl_credits_applied',
                'vl_no_credit_reason',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->foreignId('linked_request_id')->nullable()->after('sl_no_credit_reason')->constrained('leave_requests')->nullOnDelete();
            $table->boolean('vl_credits_applied')->nullable()->after('linked_request_id');
            $table->text('vl_no_credit_reason')->nullable()->after('vl_credits_applied');
        });
    }
};
