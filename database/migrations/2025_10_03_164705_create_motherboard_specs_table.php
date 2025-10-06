<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('motherboard_specs', function (Blueprint $table) {
            $table->id();

            $table->string('brand');
            $table->string('model');
            $table->string('chipset');
            $table->string('form_factor');
            $table->string('socket_type');
            $table->string('memory_type');
            $table->unsignedTinyInteger('ram_slots');
            $table->unsignedInteger('max_ram_capacity_gb');
            $table->string('max_ram_speed');
            $table->string('pcie_slots');
            $table->unsignedTinyInteger('m2_slots');
            $table->unsignedTinyInteger('sata_ports');
            $table->string('usb_ports');
            $table->string('ethernet_speed');
            $table->boolean('wifi')->default(false);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('motherboard_specs');
    }
};
