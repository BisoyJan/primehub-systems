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
        Schema::table('social_direct_messages', function (Blueprint $table) {
            $table->unsignedBigInteger('parent_id')->nullable()->after('user_id');

            $table->foreign('parent_id', 'sdm_parent_fk')
                ->references('id')
                ->on('social_direct_messages')
                ->nullOnDelete();

            $table->index(['social_direct_thread_id', 'parent_id'], 'sdm_thread_parent_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('social_direct_messages', function (Blueprint $table) {
            $table->dropForeign('sdm_parent_fk');
            $table->dropIndex('sdm_thread_parent_idx');
            $table->dropColumn('parent_id');
        });
    }
};
