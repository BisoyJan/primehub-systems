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
        Schema::table('it_concerns', function (Blueprint $table) {
            $table->string('reported_station_number')->nullable();
        });

        // An older ticket's current station may already have been corrected.
        // Only the creation record is evidence of what was actually submitted.
        $submittedStation = DB::table(config('activitylog.table_name'))
            ->select('properties->attributes->station_number')
            ->where('subject_type', 'App\\Models\\ItConcern')
            ->whereColumn('subject_id', 'it_concerns.id')
            ->where('event', 'created')
            ->oldest('id')
            ->limit(1);

        DB::table('it_concerns')->whereNull('reported_station_number')
            ->update(['reported_station_number' => $submittedStation]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('it_concerns', function (Blueprint $table) {
            $table->dropColumn('reported_station_number');
        });
    }
};
