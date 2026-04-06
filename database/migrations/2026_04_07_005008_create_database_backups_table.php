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
        Schema::create('database_backups', function (Blueprint $table) {
            $table->id();
            $table->string('filename');
            $table->string('disk')->default('local');
            $table->string('path');
            $table->unsignedBigInteger('size')->default(0);
            $table->string('status')->default('pending'); // pending, in_progress, completed, failed
            $table->text('error_message')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('database_backups');
    }
};
