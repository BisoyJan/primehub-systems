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
        Schema::table('social_groups', function (Blueprint $table) {
            $table->string('theme_color', 7)->nullable()->after('is_archived');
            $table->string('theme_background', 40)->nullable()->after('theme_color');
        });

        Schema::table('social_direct_threads', function (Blueprint $table) {
            $table->string('theme_color', 7)->nullable()->after('image_path');
            $table->string('theme_background', 40)->nullable()->after('theme_color');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('social_groups', function (Blueprint $table) {
            $table->dropColumn(['theme_color', 'theme_background']);
        });

        Schema::table('social_direct_threads', function (Blueprint $table) {
            $table->dropColumn(['theme_color', 'theme_background']);
        });
    }
};
