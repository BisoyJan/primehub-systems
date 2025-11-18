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
        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Leave details
            // VL = Vacation Leave, SL = Sick Leave, BL = Bereavement Leave
            // SPL = Solo Parent Leave, LOA = Leave of Absence
            // LDV = Leave Due to Domestic Violence, UPTO = Unpaid Personal Time Off
            $table->enum('leave_type', ['VL', 'SL', 'BL', 'SPL', 'LOA', 'LDV', 'UPTO']);
            $table->date('start_date');
            $table->date('end_date');
            $table->decimal('days_requested', 5, 2); // Calculated days
            $table->text('reason');

            // Team Lead and Campaign info
            $table->string('team_lead_email');
            $table->string('campaign_department');

            // Medical certificate (for SL)
            $table->boolean('medical_cert_submitted')->default(false);

            // Status tracking
            $table->enum('status', ['pending', 'approved', 'denied', 'cancelled'])->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();

            // Leave credits tracking (null for non-credited leave types)
            $table->decimal('credits_deducted', 5, 2)->nullable();
            $table->year('credits_year')->nullable(); // Which year's credits were used

            // Attendance points at time of request
            $table->decimal('attendance_points_at_request', 5, 2)->default(0);

            // Auto-rejection tracking
            $table->boolean('auto_rejected')->default(false);
            $table->text('auto_rejection_reason')->nullable();

            $table->timestamps();

            // Indexes
            $table->index(['user_id', 'status']);
            $table->index(['start_date', 'end_date']);
            $table->index(['leave_type', 'status']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
    }
};
