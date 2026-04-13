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
        // Add bios_release_date to pc_specs if it doesn't exist
        if (! Schema::hasColumn('pc_specs', 'bios_release_date')) {
            Schema::table('pc_specs', function (Blueprint $table) {
                $table->date('bios_release_date')->nullable()->after('available_ports');
            });
        }

        // Remove release_date from processor_specs
        if (Schema::hasColumn('processor_specs', 'release_date')) {
            Schema::table('processor_specs', function (Blueprint $table) {
                $table->dropColumn('release_date');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Re-add release_date to processor_specs
        Schema::table('processor_specs', function (Blueprint $table) {
            $table->date('release_date')->nullable()->after('boost_clock_ghz');
        });

        // Remove bios_release_date from pc_specs
        Schema::table('pc_specs', function (Blueprint $table) {
            $table->dropColumn('bios_release_date');
        });
    }
};
