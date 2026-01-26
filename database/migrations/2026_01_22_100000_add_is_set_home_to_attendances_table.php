<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * "Set Home" indicates employee was sent home early (not their fault).
     * When enabled, undertime violation points should NOT be created.
     */
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->boolean('is_set_home')->default(false)->after('undertime_minutes')
                ->comment('If true, employee was sent home early - no undertime points');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn('is_set_home');
        });
    }
};
