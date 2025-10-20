<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pc_specs', function (Blueprint $table) {
            $table->id();

            $table->string('pc_number')->nullable();
            $table->string('manufacturer');
            $table->string('model');
            $table->string('form_factor');
            $table->string('memory_type');
            $table->unsignedTinyInteger('ram_slots');
            $table->unsignedInteger('max_ram_capacity_gb');
            $table->string('max_ram_speed');
            $table->unsignedTinyInteger('m2_slots');
            $table->unsignedTinyInteger('sata_ports');
            $table->text('issue')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pc_specs');
    }
};
