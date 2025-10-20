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
        Schema::create('pc_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_station_id')->nullable()->constrained('stations')->onDelete('set null');
            $table->foreignId('to_station_id')->constrained('stations')->onDelete('cascade');
            $table->foreignId('pc_spec_id')->constrained('pc_specs')->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('transfer_type')->default('assign'); // 'assign', 'swap', 'remove'
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pc_transfers');
    }
};
