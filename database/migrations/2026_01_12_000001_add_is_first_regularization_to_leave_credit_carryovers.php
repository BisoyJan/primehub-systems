<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * This migration adds support for first-time regularization credit transfer.
     * When an employee is regularized (6 months after hire), ALL their accrued
     * credits from the hire year are transferred to the regularization year
     * without the 4-credit cap. Subsequent year carryovers are capped at 4.
     */
    public function up(): void
    {
        Schema::table('leave_credit_carryovers', function (Blueprint $table) {
            // Track if this is a first-time regularization carryover (no cap applies)
            $table->boolean('is_first_regularization')
                ->default(false)
                ->after('to_year')
                ->comment('True if this is first-time regularization carryover (no cap, all credits transferred)');

            // Track the regularization date for reference
            $table->date('regularization_date')
                ->nullable()
                ->after('is_first_regularization')
                ->comment('Date when employee was regularized (6 months after hire)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leave_credit_carryovers', function (Blueprint $table) {
            $table->dropColumn(['is_first_regularization', 'regularization_date']);
        });
    }
};
