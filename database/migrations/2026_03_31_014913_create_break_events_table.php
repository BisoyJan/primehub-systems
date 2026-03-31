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
        Schema::create('break_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('break_session_id')->constrained('break_sessions')->onDelete('cascade');
            $table->enum('action', ['start', 'pause', 'resume', 'end', 'time_up', 'reset']);
            $table->integer('remaining_seconds')->nullable();
            $table->integer('overage_seconds')->default(0);
            $table->string('reason')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index('break_session_id');
            $table->index('action');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('break_events');
    }
};
