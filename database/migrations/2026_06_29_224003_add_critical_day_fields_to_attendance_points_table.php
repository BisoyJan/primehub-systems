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
        Schema::table('attendance_points', function (Blueprint $table) {
            $table->decimal('multiplier', 3, 2)->default(1.00)->after('points');
            $table->boolean('is_critical_day')->default(false)->after('multiplier');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendance_points', function (Blueprint $table) {
            $table->dropColumn(['multiplier', 'is_critical_day']);
        });
    }
};
