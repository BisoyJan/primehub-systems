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
        Schema::table('users', function (Blueprint $table) {
            $table->string('name_search_index', 255)->nullable()->index()->after('last_name');
        });

        Schema::table('biometric_records', function (Blueprint $table) {
            $table->string('name_search_index', 255)->nullable()->index()->after('employee_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('name_search_index');
        });

        Schema::table('biometric_records', function (Blueprint $table) {
            $table->dropColumn('name_search_index');
        });
    }
};
