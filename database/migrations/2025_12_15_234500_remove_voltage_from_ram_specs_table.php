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
        Schema::table('ram_specs', function (Blueprint $table) {
            $table->dropColumn('voltage');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ram_specs', function (Blueprint $table) {
            $table->decimal('voltage', 4, 2)->after('form_factor');
        });
    }
};
