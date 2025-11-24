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
        Schema::create('medication_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->enum('medication_type', [
                'Declogen',
                'Biogesic',
                'Mefenamic Acid',
                'Kremil-S',
                'Cetirizine',
                'Saridon',
                'Diatabs'
            ]);
            $table->text('reason');
            $table->enum('onset_of_symptoms', [
                'Just today',
                'More than 1 day',
                'More than 1 week'
            ]);
            $table->boolean('agrees_to_policy')->default(false);
            $table->enum('status', ['pending', 'approved', 'dispensed', 'rejected'])->default('pending');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('admin_notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('medication_requests');
    }
};
