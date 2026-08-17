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
        Schema::create('social_direct_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_direct_thread_id')->constrained('social_direct_threads')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('message');
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();

            $table->index(['social_direct_thread_id', 'created_at'], 'social_direct_messages_thread_created_idx');
            $table->index('user_id', 'social_direct_messages_user_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('social_direct_messages');
    }
};
