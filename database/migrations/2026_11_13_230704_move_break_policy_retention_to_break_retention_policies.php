<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Carry the longest per-break-policy retention over as a single global rule,
     * so no session is purged sooner than it would have been before the move.
     */
    public function up(): void
    {
        $retentionMonths = DB::table('break_policies')
            ->whereNotNull('retention_months')
            ->where('retention_months', '>', 0)
            ->max('retention_months');

        if ($retentionMonths) {
            DB::table('break_retention_policies')->insert([
                'name' => 'Break Sessions Retention',
                'description' => 'Migrated from the retention setting on break policies.',
                'retention_months' => $retentionMonths,
                'applies_to_type' => 'global',
                'applies_to_id' => null,
                'priority' => 100,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('break_policies', function (Blueprint $table) {
            $table->dropColumn('retention_months');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('break_policies', function (Blueprint $table) {
            $table->unsignedSmallInteger('retention_months')->nullable()->after('is_active');
        });

        $retentionMonths = DB::table('break_retention_policies')
            ->where('is_active', true)
            ->where('applies_to_type', 'global')
            ->orderByDesc('priority')
            ->value('retention_months');

        if ($retentionMonths) {
            DB::table('break_policies')->update(['retention_months' => $retentionMonths]);
        }
    }
};
