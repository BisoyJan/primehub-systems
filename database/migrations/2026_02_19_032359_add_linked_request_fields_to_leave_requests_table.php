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
            // Link companion requests (e.g., VL→UPTO split creates a linked UPTO request)
            $table->foreignId('linked_request_id')->nullable()->after('sl_no_credit_reason')
                ->constrained('leave_requests')->nullOnDelete();

            // VL credit tracking (mirrors sl_credits_applied / sl_no_credit_reason)
            $table->boolean('vl_credits_applied')->nullable()->after('linked_request_id');
            $table->string('vl_no_credit_reason')->nullable()->after('vl_credits_applied');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropForeign(['linked_request_id']);
            $table->dropColumn(['linked_request_id', 'vl_credits_applied', 'vl_no_credit_reason']);
        });
    }
};
