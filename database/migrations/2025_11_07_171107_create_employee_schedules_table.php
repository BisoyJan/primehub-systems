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
        Schema::create('employee_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('campaign_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('site_id')->nullable()->constrained()->onDelete('set null');
            $table->enum('shift_type', ['night_shift', 'morning_shift', 'afternoon_shift', 'utility_24h'])->default('night_shift');
            $table->time('scheduled_time_in'); // e.g., "22:00:00" for 10 PM
            $table->time('scheduled_time_out'); // e.g., "07:00:00" for 7 AM next day
            $table->json('work_days')->nullable(); // Days of the week they work
            $table->integer('grace_period_minutes')->default(15); // Tardy threshold
            $table->boolean('is_active')->default(true);
            $table->date('effective_date'); // When this schedule starts
            $table->date('end_date')->nullable(); // When this schedule ends
            $table->timestamps();

            // Indexes for performance
            $table->index(['user_id', 'is_active', 'effective_date']);
            $table->index('campaign_id');
            $table->index('site_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_schedules');
    }
};
