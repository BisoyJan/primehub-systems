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
        Schema::create('social_direct_thread_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_direct_thread_id');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('last_read_at')->nullable();
            $table->timestamps();

            $table
                ->foreign('social_direct_thread_id', 'sdtp_thread_fk')
                ->references('id')
                ->on('social_direct_threads')
                ->cascadeOnDelete();

            $table->unique(['social_direct_thread_id', 'user_id'], 'social_direct_thread_participants_unique');
            $table->index(['user_id', 'last_read_at'], 'social_direct_thread_participants_user_read_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('social_direct_thread_participants');
    }
};
