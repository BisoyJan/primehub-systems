<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates table to track individual denied dates for partial denial leave requests.
     * When a reviewer approves some dates but denies others, this table stores the denied dates.
     */
    public function up(): void
    {
        Schema::create('leave_request_denied_dates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_request_id')->constrained()->onDelete('cascade');
            $table->date('denied_date');
            $table->string('denial_reason')->nullable();
            $table->foreignId('denied_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();

            // Index for efficient lookups
            $table->index(['leave_request_id', 'denied_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leave_request_denied_dates');
    }
};
