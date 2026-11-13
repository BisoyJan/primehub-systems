<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('break_retention_policies', function (Blueprint $table) {
            $table->enum('break_type', ['all', 'break', 'lunch', 'combined'])->default('all')->after('applies_to_id');
        });
    }

    /**
     * Policies scoped to one break type are removed first: without the column
     * they would silently start applying to every break type.
     */
    public function down(): void
    {
        DB::table('break_retention_policies')->where('break_type', '!=', 'all')->delete();

        Schema::table('break_retention_policies', function (Blueprint $table) {
            $table->dropColumn('break_type');
        });
    }
};
