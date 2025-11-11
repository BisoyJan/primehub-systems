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
        Schema::create('biometric_retention_policies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->integer('retention_months')->default(3);
            $table->string('applies_to_type'); // 'site', 'department', 'global'
            $table->unsignedBigInteger('applies_to_id')->nullable(); // site_id or department_id
            $table->integer('priority')->default(0); // Higher priority wins
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['applies_to_type', 'applies_to_id']);
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('biometric_retention_policies');
    }
};
