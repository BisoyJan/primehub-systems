<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('break_policies', function (Blueprint $table) {
            $table->string('shift_reset_time', 5)->default('06:00')->after('is_active');
        });

        DB::statement("ALTER TABLE break_sessions MODIFY COLUMN status ENUM('active', 'paused', 'completed', 'overage', 'auto_ended') DEFAULT 'active'");
    }

    public function down(): void
    {
        Schema::table('break_policies', function (Blueprint $table) {
            $table->dropColumn('shift_reset_time');
        });

        DB::statement("ALTER TABLE break_sessions MODIFY COLUMN status ENUM('active', 'paused', 'completed', 'overage') DEFAULT 'active'");
    }
};
