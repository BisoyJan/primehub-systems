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
            $table->dropColumn(['m2_slots', 'sata_ports']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pc_specs', function (Blueprint $table) {
            $table->unsignedTinyInteger('m2_slots')->after('memory_type');
            $table->unsignedTinyInteger('sata_ports')->after('m2_slots');
        });
    }
};
