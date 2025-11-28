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
        Schema::create('monitor_pc_spec', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pc_spec_id')->constrained('pc_specs')->onDelete('cascade');
            $table->foreignId('monitor_spec_id')->constrained('monitor_specs')->onDelete('cascade');
            $table->integer('quantity')->default(1);
            $table->timestamps();

            $table->unique(['pc_spec_id', 'monitor_spec_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('monitor_pc_spec');
    }
};
