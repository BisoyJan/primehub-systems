<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pc_spec_ram_spec', function (Blueprint $table) {
            $table->foreignId('pc_spec_id')
                ->constrained('pc_specs')
                ->cascadeOnDelete();
            $table->foreignId('ram_spec_id')
                ->constrained('ram_specs')
                ->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->timestamps();

            $table->primary(['pc_spec_id', 'ram_spec_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pc_spec_ram_spec');
    }
};
