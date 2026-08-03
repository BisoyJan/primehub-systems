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
        Schema::table('coaching_sessions', function (Blueprint $table) {
            $table->text('confidential_comment')->nullable()->after('agent_response_at');
            $table->timestamp('confidential_comment_at')->nullable()->after('confidential_comment');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('coaching_sessions', function (Blueprint $table) {
            $table->dropColumn(['confidential_comment', 'confidential_comment_at']);
        });
    }
};
