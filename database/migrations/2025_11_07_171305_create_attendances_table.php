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
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('employee_schedule_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('leave_request_id')->nullable()->constrained()->onDelete('set null');
            $table->date('shift_date'); // The date of the shift (e.g., Monday for Mon night shift)
            $table->time('scheduled_time_in')->nullable();
            $table->time('scheduled_time_out')->nullable();
            $table->dateTime('actual_time_in')->nullable();
            $table->foreignId('bio_in_site_id')->nullable()->constrained('sites')->onDelete('set null');
            $table->dateTime('actual_time_out')->nullable();
            $table->foreignId('bio_out_site_id')->nullable()->constrained('sites')->onDelete('set null');
            $table->enum('status', [
                'on_time',
                'tardy',
                'half_day_absence',
                'advised_absence',
                'ncns',
                'undertime',
                'failed_bio_in',
                'failed_bio_out',
                'present_no_bio',
                'needs_manual_review',
                'non_work_day',
                'on_leave'
            ])->default('on_time');
            $table->string('secondary_status')->nullable();
            $table->integer('tardy_minutes')->nullable();
            $table->integer('undertime_minutes')->nullable();
            $table->integer('overtime_minutes')->nullable();
            $table->boolean('overtime_approved')->default(false);
            $table->timestamp('overtime_approved_at')->nullable();
            $table->foreignId('overtime_approved_by')->nullable()->constrained('users');
            $table->boolean('is_advised')->default(false); // Pre-notified absence
            $table->boolean('admin_verified')->default(false); // For failed bio cases
            $table->boolean('is_cross_site_bio')->default(false); // Flag if bio'd at different site
            $table->text('verification_notes')->nullable();
            $table->text('notes')->nullable();
            $table->json('warnings')->nullable(); // Warnings for manual review
            $table->date('date_from')->nullable();
            $table->date('date_to')->nullable();
            $table->timestamps();

            // Indexes for performance
            $table->index(['user_id', 'shift_date']);
            $table->index('employee_schedule_id');
            $table->index('leave_request_id');
            $table->index('bio_in_site_id');
            $table->index('bio_out_site_id');
            $table->index('status');
            $table->index('shift_date');
            $table->index('is_cross_site_bio');
            $table->index('date_from');
            $table->index('date_to');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
