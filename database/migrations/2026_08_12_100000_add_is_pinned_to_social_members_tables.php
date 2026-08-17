<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_group_members', function (Blueprint $table) {
            $table->boolean('is_pinned')->default(false)->after('last_read_at');
        });

        Schema::table('social_direct_thread_participants', function (Blueprint $table) {
            $table->boolean('is_pinned')->default(false)->after('last_read_at');
        });
    }

    public function down(): void
    {
        Schema::table('social_group_members', function (Blueprint $table) {
            $table->dropColumn('is_pinned');
        });

        Schema::table('social_direct_thread_participants', function (Blueprint $table) {
            $table->dropColumn('is_pinned');
        });
    }
};
