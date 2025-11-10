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
        Schema::create('attendance_uploads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('uploaded_by')->constrained('users')->onDelete('cascade');
            $table->foreignId('biometric_site_id')->nullable()->constrained('sites')->onDelete('set null');
            $table->string('original_filename');
            $table->string('stored_filename');
            $table->date('shift_date'); // What shift date this file is for
            $table->integer('total_records')->default(0);
            $table->integer('processed_records')->default(0);
            $table->integer('matched_employees')->default(0);
            $table->integer('unmatched_names')->default(0);
            $table->json('unmatched_names_list')->nullable(); // List of names not found in system
            $table->json('date_warnings')->nullable(); // Date validation warnings
            $table->json('dates_found')->nullable(); // Actual dates found in file
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->text('notes')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            // Indexes
            $table->index('shift_date');
            $table->index('uploaded_by');
            $table->index('biometric_site_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_uploads');
    }
};
