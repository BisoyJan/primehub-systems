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
            // GBRO prediction date - calculated as reference_date + 60 days
            // This is separate from expires_at which is for SRO (6 months/1 year)
            $table->date('gbro_expires_at')->nullable()->after('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendance_points', function (Blueprint $table) {
            $table->dropColumn('gbro_expires_at');
        });
    }
};
