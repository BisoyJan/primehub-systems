<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateStocksTable extends Migration
{
    /**
     * Run the migrations.
     *
     * This creates a polymorphic stocks table used by RamSpec, DiskSpec, ProcessorSpec, etc.
     * Columns:
     *  - id
     *  - stockable_type, stockable_id (polymorphic relation)
     *  - quantity (unsigned integer, defaults to 0)
     *  - reserved (optional unsigned integer, defaults to 0) — useful if you later track reserved amounts separately
     *  - location (nullable string) — optional metadata (warehouse, shelf)
     *  - notes (nullable text) — optional admin notes
     *  - timestamps
     */
    public function up()
    {
        Schema::create('stocks', function (Blueprint $table) {
            $table->id();

            // polymorphic relation to various spec models
            $table->string('stockable_type');
            $table->unsignedBigInteger('stockable_id');

            $table->unsignedInteger('quantity')->default(0);
            $table->unsignedInteger('reserved')->default(0);

            $table->string('location')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            // index to speed up polymorphic lookups
            $table->index(['stockable_type', 'stockable_id'], 'stocks_stockable_type_id_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down()
    {
        Schema::dropIfExists('stocks');
    }
}
