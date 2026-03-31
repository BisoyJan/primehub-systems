<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('break_sessions', function (Blueprint $table) {
            $table->dropForeign(['station_id']);
            $table->dropColumn('station_id');
            $table->string('station')->nullable()->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('break_sessions', function (Blueprint $table) {
            $table->dropColumn('station');
            $table->foreignId('station_id')->nullable()->after('user_id')->constrained('stations')->onDelete('set null');
        });
    }
};
