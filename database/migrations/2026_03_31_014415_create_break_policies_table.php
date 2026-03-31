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
        Schema::create('break_policies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->integer('max_breaks')->default(2);
            $table->integer('break_duration_minutes')->default(15);
            $table->integer('max_lunch')->default(1);
            $table->integer('lunch_duration_minutes')->default(60);
            $table->integer('grace_period_minutes')->default(0);
            $table->json('allowed_pause_reasons')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('break_policies');
    }
};
