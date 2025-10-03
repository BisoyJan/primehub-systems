<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('motherboard_spec_disk_spec', function (Blueprint $table) {
            $table->foreignId('motherboard_spec_id')
                ->constrained('motherboard_specs')
                ->cascadeOnDelete();
            $table->foreignId('disk_spec_id')
                ->constrained('disk_specs')
                ->cascadeOnDelete();

            $table->primary(['motherboard_spec_id', 'disk_spec_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('motherboard_spec_disk_spec');
    }
};
