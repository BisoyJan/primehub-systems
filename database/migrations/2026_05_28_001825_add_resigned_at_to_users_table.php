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
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('resigned_at')->nullable()->after('approved_at');
        });

        // Backfill resigned_at for existing resigned employees
        // Resigned = has hired_date AND is_active = false AND is_approved = false
        DB::table('users')
            ->whereNull('deleted_at')
            ->where('is_active', false)
            ->whereNotNull('hired_date')
            ->where('is_approved', false)
            ->update(['resigned_at' => DB::raw('updated_at')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('resigned_at');
        });
    }
};
