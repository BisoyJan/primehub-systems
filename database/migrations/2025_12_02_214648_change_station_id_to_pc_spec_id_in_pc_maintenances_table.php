<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Check if we need to do the full migration or just finish it
        $hasStationId = Schema::hasColumn('pc_maintenances', 'station_id');
        $hasPcSpecId = Schema::hasColumn('pc_maintenances', 'pc_spec_id');

        if ($hasStationId && !$hasPcSpecId) {
            // Full migration needed
            // Step 1: Add pc_spec_id column as nullable first
            Schema::table('pc_maintenances', function (Blueprint $table) {
                $table->unsignedBigInteger('pc_spec_id')->nullable()->after('id');
            });

            // Step 2: Migrate data - get pc_spec_id from station's relationship
            DB::statement('
                UPDATE pc_maintenances pm
                INNER JOIN stations s ON pm.station_id = s.id
                SET pm.pc_spec_id = s.pc_spec_id
                WHERE s.pc_spec_id IS NOT NULL
            ');

            // Step 3: Delete records where pc_spec_id is still null (station had no pc_spec)
            DB::table('pc_maintenances')->whereNull('pc_spec_id')->delete();

            // Step 4: Drop station_id foreign key and column
            Schema::table('pc_maintenances', function (Blueprint $table) {
                $table->dropForeign(['station_id']);
                $table->dropColumn('station_id');
            });
        }

        // Step 5: Make pc_spec_id non-nullable and add foreign key (if not already done)
        if (Schema::hasColumn('pc_maintenances', 'pc_spec_id')) {
            // Check if foreign key exists
            $foreignKeys = DB::select("
                SELECT CONSTRAINT_NAME
                FROM information_schema.TABLE_CONSTRAINTS
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'pc_maintenances'
                AND CONSTRAINT_TYPE = 'FOREIGN KEY'
                AND CONSTRAINT_NAME = 'pc_maintenances_pc_spec_id_foreign'
            ");

            if (empty($foreignKeys)) {
                Schema::table('pc_maintenances', function (Blueprint $table) {
                    // Ensure it's not nullable
                    $table->unsignedBigInteger('pc_spec_id')->nullable(false)->change();
                    // Add foreign key
                    $table->foreign('pc_spec_id')->references('id')->on('pc_specs')->onDelete('cascade');
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Step 1: Add station_id column as nullable first
        Schema::table('pc_maintenances', function (Blueprint $table) {
            $table->unsignedBigInteger('station_id')->nullable()->after('id');
        });

        // Step 2: Migrate data - get first station with this pc_spec_id
        DB::statement('
            UPDATE pc_maintenances pm
            INNER JOIN (
                SELECT pc_spec_id, MIN(id) as station_id
                FROM stations
                WHERE pc_spec_id IS NOT NULL
                GROUP BY pc_spec_id
            ) s ON pm.pc_spec_id = s.pc_spec_id
            SET pm.station_id = s.station_id
        ');

        // Step 3: Delete records where station_id is still null
        DB::table('pc_maintenances')->whereNull('station_id')->delete();

        // Step 4: Drop pc_spec_id and finalize station_id
        Schema::table('pc_maintenances', function (Blueprint $table) {
            // Drop the foreign key constraint on pc_spec_id
            $table->dropForeign(['pc_spec_id']);

            // Drop the pc_spec_id column
            $table->dropColumn('pc_spec_id');

            // Change station_id to non-nullable and add foreign key
            $table->unsignedBigInteger('station_id')->nullable(false)->change();
            $table->foreign('station_id')->references('id')->on('stations')->onDelete('cascade');
        });
    }
};
