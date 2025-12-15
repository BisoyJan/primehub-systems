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
        Schema::table('pc_specs', function (Blueprint $table) {
            $table->dropColumn(['form_factor', 'ram_slots', 'max_ram_capacity_gb', 'max_ram_speed']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pc_specs', function (Blueprint $table) {
            $table->string('form_factor')->after('model');
            $table->unsignedTinyInteger('ram_slots')->after('memory_type');
            $table->unsignedInteger('max_ram_capacity_gb')->after('ram_slots');
            $table->string('max_ram_speed')->after('max_ram_capacity_gb');
        });
    }
};
