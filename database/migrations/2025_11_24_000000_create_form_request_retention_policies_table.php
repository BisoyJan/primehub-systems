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
        Schema::create('form_request_retention_policies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->integer('retention_months');
            $table->enum('applies_to_type', ['global', 'site'])->default('global');
            $table->foreignId('applies_to_id')->nullable()->constrained('sites')->onDelete('cascade');
            $table->enum('form_type', ['all', 'leave_request', 'it_concern', 'medication_request'])->default('all');
            $table->integer('priority')->default(100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['applies_to_type', 'applies_to_id', 'form_type'], 'frp_applies_form_idx');
            $table->index(['is_active', 'priority'], 'frp_active_priority_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('form_request_retention_policies');
    }
};
