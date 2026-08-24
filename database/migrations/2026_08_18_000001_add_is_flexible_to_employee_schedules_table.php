<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('employee_schedules', function (Blueprint $table) {
            if (! Schema::hasColumn('employee_schedules', 'is_flexible')) {
                $table->boolean('is_flexible')->default(false)->after('grace_period_minutes');
            }

            if (Schema::hasColumn('employee_schedules', 'scheduled_time_in')) {
                $table->time('scheduled_time_in')->nullable()->change();
            }

            if (Schema::hasColumn('employee_schedules', 'scheduled_time_out')) {
                $table->time('scheduled_time_out')->nullable()->change();
            }
        });

        DB::table('employee_schedules')
            ->whereNull('scheduled_time_in')
            ->update(['is_flexible' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employee_schedules', function (Blueprint $table) {
            if (Schema::hasColumn('employee_schedules', 'is_flexible')) {
                $table->dropColumn('is_flexible');
            }
        });
    }
};
