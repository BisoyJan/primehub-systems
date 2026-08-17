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
            $table->string('theme_background_image_path')->nullable()->after('theme_background');
        });

        Schema::table('social_direct_threads', function (Blueprint $table) {
            $table->string('theme_background_image_path')->nullable()->after('theme_background');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('social_groups', function (Blueprint $table) {
            $table->dropColumn('theme_background_image_path');
        });

        Schema::table('social_direct_threads', function (Blueprint $table) {
            $table->dropColumn('theme_background_image_path');
        });
    }
};
