<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('biometric_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('attendance_upload_id')->constrained()->onDelete('cascade');
            $table->foreignId('site_id')->constrained()->onDelete('cascade'); // Biometric device site
            $table->string('employee_name'); // Raw name from biometric device
            $table->dateTime('datetime'); // Exact timestamp from biometric
            $table->date('record_date')->index(); // For quick date lookups
            $table->time('record_time'); // For quick time range queries
            $table->timestamps();

            // Index for fast lookups when searching for time in/out
            $table->index(['user_id', 'record_date', 'record_time']);
            $table->index(['user_id', 'datetime']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('biometric_records');
    }
};
