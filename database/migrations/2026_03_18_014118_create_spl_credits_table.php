<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates table for Solo Parent Leave (SPL) credits.
     * Each solo parent user gets 7 credits per year (lump-sum, no monthly accrual).
     * Credits do not carry over — they reset yearly.
     */
    public function up(): void
    {
        Schema::create('spl_credits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->year('year');
            $table->decimal('total_credits', 5, 2)->default(7.00);
            $table->decimal('credits_used', 5, 2)->default(0.00);
            $table->decimal('credits_balance', 5, 2)->default(7.00);
            $table->timestamps();

            $table->unique(['user_id', 'year']);
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('spl_credits');
    }
};
