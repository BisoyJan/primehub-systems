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
        Schema::create('processor_specs', function (Blueprint $table) {
            $table->id();

            // Core identifiers
            $table->string('brand'); // e.g. Intel, AMD
            $table->string('series'); // e.g. Core i5-12400

            // Compatibility
            $table->string('socket_type'); // e.g. LGA1700

            // Performance
            $table->unsignedTinyInteger('core_count'); // e.g. 6
            $table->unsignedTinyInteger('thread_count'); // e.g. 12
            $table->decimal('base_clock_ghz', 4, 2); // e.g. 2.5
            $table->decimal('boost_clock_ghz', 4, 2)->nullable(); // e.g. 4.4

            // Integrated graphics
            $table->string('integrated_graphics')->nullable(); // e.g. Intel UHD 730

            // Efficiency
            $table->unsignedSmallInteger('tdp_watts')->nullable(); // e.g. 65

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('processor_specs');
    }
};
