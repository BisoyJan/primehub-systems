<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop the legacy shift_date column from attendance_uploads.
     *
     * shift_date was always equal to date_from and has been kept only for
     * backward compatibility. All code now reads date_from directly.
     */
    public function up(): void
    {
        Schema::table('attendance_uploads', function (Blueprint $table) {
            $table->dropIndex(['shift_date']);
            $table->dropColumn('shift_date');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_uploads', function (Blueprint $table) {
            $table->date('shift_date')->nullable()->after('stored_filename')->comment('Legacy alias of date_from — kept for rollback only');
            $table->index('shift_date');
        });
    }
};
