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
        Schema::table('biometric_records', function (Blueprint $table) {
            $table->timestamp('last_processed_at')->nullable()->after('record_time');
        });
    }

    public function down(): void
    {
        Schema::table('biometric_records', function (Blueprint $table) {
            $table->dropColumn('last_processed_at');
        });
    }
};
