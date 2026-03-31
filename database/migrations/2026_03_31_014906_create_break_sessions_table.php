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
        Schema::create('break_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('session_id')->unique();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('station_id')->nullable()->constrained('stations')->onDelete('set null');
            $table->foreignId('break_policy_id')->nullable()->constrained('break_policies')->onDelete('set null');
            $table->enum('type', ['1st_break', '2nd_break', 'lunch']);
            $table->enum('status', ['active', 'paused', 'completed', 'overage'])->default('active');
            $table->integer('duration_seconds');
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->integer('remaining_seconds')->nullable();
            $table->integer('overage_seconds')->default(0);
            $table->integer('total_paused_seconds')->default(0);
            $table->string('last_pause_reason')->nullable();
            $table->string('reset_approval')->nullable();
            $table->date('shift_date');
            $table->timestamps();

            $table->index(['user_id', 'shift_date']);
            $table->index(['station_id', 'shift_date']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('break_sessions');
    }
};
