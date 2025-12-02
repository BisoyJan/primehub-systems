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
        Schema::create('pc_maintenances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pc_spec_id')->constrained('pc_specs')->onDelete('cascade');
            $table->date('last_maintenance_date');
            $table->date('next_due_date');
            $table->string('maintenance_type')->nullable(); // e.g., 'cleaning', 'hardware check', 'software update'
            $table->text('notes')->nullable();
            $table->string('performed_by')->nullable();
            $table->enum('status', ['completed', 'pending', 'overdue'])->default('pending');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pc_maintenances');
    }
};
