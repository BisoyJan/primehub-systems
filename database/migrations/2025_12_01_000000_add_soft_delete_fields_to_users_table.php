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
            $table->timestamp('deleted_at')->nullable()->after('updated_at');
            $table->foreignId('deleted_by')->nullable()->after('deleted_at')->constrained('users')->nullOnDelete();
            $table->timestamp('deletion_confirmed_at')->nullable()->after('deleted_by');
            $table->foreignId('deletion_confirmed_by')->nullable()->after('deletion_confirmed_at')->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['deleted_by']);
            $table->dropForeign(['deletion_confirmed_by']);
            $table->dropColumn(['deleted_at', 'deleted_by', 'deletion_confirmed_at', 'deletion_confirmed_by']);
        });
    }
};
