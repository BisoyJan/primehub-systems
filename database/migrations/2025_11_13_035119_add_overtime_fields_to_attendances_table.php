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
        Schema::table('attendances', function (Blueprint $table) {
            $table->integer('overtime_minutes')->nullable()->after('undertime_minutes');
            $table->boolean('overtime_approved')->default(false)->after('overtime_minutes');
            $table->timestamp('overtime_approved_at')->nullable()->after('overtime_approved');
            $table->foreignId('overtime_approved_by')->nullable()->constrained('users')->after('overtime_approved_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropForeign(['overtime_approved_by']);
            $table->dropColumn(['overtime_minutes', 'overtime_approved', 'overtime_approved_at', 'overtime_approved_by']);
        });
    }
};
