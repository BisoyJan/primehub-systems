<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ensure attendance_points.attendance_id has ON DELETE CASCADE.
     * The original creation migration already defines this; this migration
     * re-creates the constraint on any database that was deployed before
     * the cascade was present.
     */
    public function up(): void
    {
        Schema::table('attendance_points', function (Blueprint $table) {
            $table->dropForeign(['attendance_id']);
            $table->foreign('attendance_id')
                ->references('id')
                ->on('attendances')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('attendance_points', function (Blueprint $table) {
            $table->dropForeign(['attendance_id']);
            $table->foreign('attendance_id')
                ->references('id')
                ->on('attendances')
                ->onDelete('restrict');
        });
    }
};
