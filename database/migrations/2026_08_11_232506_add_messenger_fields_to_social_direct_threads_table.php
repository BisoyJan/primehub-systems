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
        Schema::table('social_direct_threads', function (Blueprint $table) {
            $table->string('name')->nullable()->after('created_by');
            $table->boolean('is_group')->default(false)->after('name');
            $table->string('image_path')->nullable()->after('is_group');

            $table->index('is_group', 'social_direct_threads_is_group_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('social_direct_threads', function (Blueprint $table) {
            $table->dropIndex('social_direct_threads_is_group_idx');
            $table->dropColumn(['name', 'is_group', 'image_path']);
        });
    }
};
