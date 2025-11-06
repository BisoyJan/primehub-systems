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
        Schema::create('attendance_logs', function (Blueprint $table) {
            $table->id();
            $table->string('device_no')->nullable();
            $table->string('user_id_from_file'); // Employee ID from the txt file (varies by device)
            $table->string('employee_name'); // Name is the true identifier across devices
            $table->string('mode')->default('FP'); // Fingerprint, etc.
            $table->dateTime('log_datetime');
            $table->date('file_date'); // The date from the filename or extracted from file
            $table->timestamps();

            // Index for faster lookups - name-based matching for multiple biometric devices
            $table->index('employee_name'); // Case-insensitive matching in queries
            $table->index('log_datetime');
            $table->index('file_date');
            $table->index(['employee_name', 'log_datetime']); // Composite for time matching
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_logs');
    }
};
