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
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->string('employee_name'); // Primary identifier (normalized for matching)
            $table->string('user_id_from_file')->nullable(); // Keep for reference, but not reliable across devices
            $table->foreignId('site_id')->nullable()->constrained('sites')->onDelete('set null');
            $table->string('shift')->nullable(); // morning, afternoon, night
            $table->string('status')->default('present'); // present, absent, late, incomplete
            $table->dateTime('time_in')->nullable();
            $table->dateTime('time_out')->nullable();
            $table->integer('duration_minutes')->nullable(); // Calculated duration
            $table->text('remarks')->nullable();
            $table->timestamps();

            // Indexes
            $table->index('employee_name'); // Name-based matching
            $table->index(['user_id', 'time_in']);
            $table->index('site_id');
            $table->index('status');
            $table->index('time_in'); // For date filtering
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
