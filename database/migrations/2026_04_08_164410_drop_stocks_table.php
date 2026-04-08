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
        Schema::dropIfExists('stocks');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('stocks', function (Blueprint $table) {
            $table->id();
            $table->morphs('stockable');
            $table->integer('quantity')->default(0);
            $table->integer('reserved')->default(0);
            $table->string('location')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }
};
