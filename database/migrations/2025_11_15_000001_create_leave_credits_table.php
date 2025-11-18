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
        Schema::create('leave_credits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('credits_earned', 8, 2)->default(0); // Monthly accrual (1.25 or 1.5)
            $table->decimal('credits_used', 8, 2)->default(0); // Deducted when leave approved
            $table->decimal('credits_balance', 8, 2)->default(0); // Current balance
            $table->year('year'); // Year these credits belong to
            $table->unsignedTinyInteger('month'); // Month (1-12)
            $table->date('accrued_at'); // Date when credits were added
            $table->timestamps();

            // Ensure one record per user per month per year
            $table->unique(['user_id', 'year', 'month']);

            $table->index(['user_id', 'year']);
            $table->index('accrued_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leave_credits');
    }
};
