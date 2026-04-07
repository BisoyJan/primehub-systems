<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coaching_sessions', function (Blueprint $table) {
            $table->text('agent_response')->nullable()->after('ack_comment');
            $table->timestamp('agent_response_at')->nullable()->after('agent_response');
        });
    }

    public function down(): void
    {
        Schema::table('coaching_sessions', function (Blueprint $table) {
            $table->dropColumn(['agent_response', 'agent_response_at']);
        });
    }
};
