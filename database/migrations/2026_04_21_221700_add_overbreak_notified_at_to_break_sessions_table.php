<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('break_sessions', function (Blueprint $table) {
            $table->timestamp('overbreak_notified_at')->nullable()->after('overage_seconds');
            $table->index('overbreak_notified_at');
        });
    }

    public function down(): void
    {
        Schema::table('break_sessions', function (Blueprint $table) {
            $table->dropIndex(['overbreak_notified_at']);
            $table->dropColumn('overbreak_notified_at');
        });
    }
};
