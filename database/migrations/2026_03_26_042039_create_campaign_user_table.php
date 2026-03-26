<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('campaign_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('campaign_id')->constrained()->onDelete('cascade');
            $table->timestamps();

            $table->unique(['user_id', 'campaign_id']);
        });

        // Migrate existing Team Lead campaign assignments from active schedules
        DB::statement("
            INSERT INTO campaign_user (user_id, campaign_id, created_at, updated_at)
            SELECT DISTINCT es.user_id, es.campaign_id, NOW(), NOW()
            FROM employee_schedules es
            INNER JOIN users u ON u.id = es.user_id
            WHERE u.role = 'Team Lead'
              AND es.is_active = 1
              AND es.campaign_id IS NOT NULL
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('campaign_user');
    }
};
