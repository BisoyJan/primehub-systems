<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pc_spec_processor_spec', function (Blueprint $table) {
            $table->foreignId('pc_spec_id')
                ->constrained('pc_specs')
                ->cascadeOnDelete();
            $table->foreignId('processor_spec_id')
                ->constrained('processor_specs')
                ->cascadeOnDelete();

            $table->primary(['pc_spec_id', 'processor_spec_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pc_spec_processor_spec');
    }
};
