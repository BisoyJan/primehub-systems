<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates table to track per-day statuses for Sick Leave requests.
     * Each day in an SL request can have a different status:
     * - sl_credited: Paid day (deducted from leave credits)
     * - ncns: No Call No Show (unpaid, gets attendance point)
     * - advised_absence: Agent informed but no credits (UPTO - Unpaid Time Off)
     */
    public function up(): void
    {
        Schema::create('leave_request_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_request_id')->constrained()->onDelete('cascade');
            $table->date('date');
            $table->enum('day_status', ['pending', 'sl_credited', 'ncns', 'advised_absence'])->default('pending');
            $table->text('notes')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('assigned_at')->nullable();
            $table->timestamps();

            // Each date can only appear once per leave request
            $table->unique(['leave_request_id', 'date']);
            // Index for efficient lookups
            $table->index(['leave_request_id', 'day_status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leave_request_days');
    }
};
