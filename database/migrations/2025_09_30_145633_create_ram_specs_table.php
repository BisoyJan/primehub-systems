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
        Schema::create('ram_specs', function (Blueprint $table) {
            $table->id();
            $table->string('manufacturer');
            $table->string('model');
            $table->unsignedBigInteger('capacity_gb');
            $table->string('type'); // e.g. DDR4, DDR5
            $table->string('speed'); // e.g. 3200MHz
            $table->string('form_factor'); // e.g. DIMM, SO-DIMM
            $table->decimal('voltage', 4, 2);  // e.g. 1.2, 1.35
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ram_specs');
    }
};
