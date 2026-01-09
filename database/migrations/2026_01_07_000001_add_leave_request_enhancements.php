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
     * 1. SL credit tracking (whether credits applied and reason if not)
     * 2. Partial denial support (when only some requested dates are approved)
     */
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            // SL credit tracking fields
            $table->boolean('sl_credits_applied')->nullable()->after('credits_year');
            $table->string('sl_no_credit_reason')->nullable()->after('sl_credits_applied');

            // Partial denial fields
            $table->boolean('has_partial_denial')->default(false)->after('auto_rejection_reason');
            $table->decimal('approved_days', 5, 2)->nullable()->after('has_partial_denial');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropColumn([
                'sl_credits_applied',
                'sl_no_credit_reason',
                'has_partial_denial',
                'approved_days',
            ]);
        });
    }
};
