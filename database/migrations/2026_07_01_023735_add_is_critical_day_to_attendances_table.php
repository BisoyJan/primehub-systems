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
        if (! Schema::hasColumn('attendances', 'is_critical_day')) {
            Schema::table('attendances', function (Blueprint $table) {
                $table->boolean('is_critical_day')->default(false)->after('admin_verified');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('attendances', 'is_critical_day')) {
            Schema::table('attendances', function (Blueprint $table) {
                $table->dropColumn('is_critical_day');
            });
        }
    }
};
