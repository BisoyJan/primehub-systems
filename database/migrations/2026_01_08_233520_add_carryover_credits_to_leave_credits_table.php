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
        // Create a separate table to track carryover credits for cash conversion
        Schema::create('leave_credit_carryovers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->decimal('credits_from_previous_year', 8, 2)->comment('Total unused credits from previous year');
            $table->decimal('carryover_credits', 8, 2)->comment('Credits carried over (max 4)');
            $table->decimal('forfeited_credits', 8, 2)->default(0)->comment('Credits beyond max that were forfeited');
            $table->year('from_year')->comment('The year credits came from');
            $table->year('to_year')->comment('The year credits are carried to');
            $table->boolean('cash_converted')->default(false)->comment('Whether cash conversion has been processed');
            $table->date('cash_converted_at')->nullable()->comment('Date when cash conversion was processed');
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            // Each user can only have one carryover record per year transition
            $table->unique(['user_id', 'from_year', 'to_year']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leave_credit_carryovers');
    }
};
