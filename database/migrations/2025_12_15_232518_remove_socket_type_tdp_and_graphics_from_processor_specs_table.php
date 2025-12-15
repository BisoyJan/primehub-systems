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
        Schema::table('processor_specs', function (Blueprint $table) {
            $table->dropColumn(['socket_type', 'integrated_graphics', 'tdp_watts']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('processor_specs', function (Blueprint $table) {
            $table->string('socket_type')->after('model');
            $table->string('integrated_graphics')->nullable()->after('boost_clock_ghz');
            $table->unsignedSmallInteger('tdp_watts')->nullable()->after('integrated_graphics');
        });
    }
};
