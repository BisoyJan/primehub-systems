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
        Schema::create('gbro_anomaly_logs', function (Blueprint $table) {
            $table->id();
            $table->string('batch_id', 64)->index();
            // Trigger source: unexcuse | manual_write | scheduled | manual_run
            $table->string('trigger', 32)->index();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('attendance_point_id')->nullable()->constrained()->nullOnDelete();
            // Anomaly type: STALE_PENDING_GBRO, STALE_PENDING_SRO, GBRO_DATE_MISMATCH,
            // GBRO_DATE_MISASSIGNED, ORPHAN_GBRO_DATE, EXCUSED_HAS_GBRO_DATE,
            // GBRO_ELIGIBILITY_MISMATCH, EXPIRES_AT_OVERFLOW
            $table->string('type', 64)->index();
            $table->string('expected', 255)->nullable();
            $table->string('actual', 255)->nullable();
            $table->boolean('repaired')->default(false)->index();
            $table->json('context')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'type']);
            $table->index(['created_at', 'repaired']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gbro_anomaly_logs');
    }
};
