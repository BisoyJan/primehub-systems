<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitor_specs', function (Blueprint $table) {
            $table->id();
            $table->string('brand');
            $table->string('model');
            $table->decimal('screen_size', 4, 1); // e.g., 27.0 inches
            $table->string('resolution'); // e.g., "1920x1080", "2560x1440"
            $table->string('panel_type'); // IPS, VA, TN, OLED
            $table->json('ports')->nullable(); // ['HDMI', 'DisplayPort', 'USB-C']
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitor_specs');
    }
};
