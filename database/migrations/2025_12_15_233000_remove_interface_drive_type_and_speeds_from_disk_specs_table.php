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
        Schema::table('disk_specs', function (Blueprint $table) {
            $table->dropColumn(['interface', 'drive_type', 'sequential_read_mb', 'sequential_write_mb']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('disk_specs', function (Blueprint $table) {
            $table->string('interface')->after('capacity_gb');
            $table->string('drive_type')->after('interface');
            $table->unsignedInteger('sequential_read_mb')->after('drive_type');
            $table->unsignedInteger('sequential_write_mb')->after('sequential_read_mb');
        });
    }
};
