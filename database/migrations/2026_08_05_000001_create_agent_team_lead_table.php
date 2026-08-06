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
        Schema::create('agent_team_lead', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_lead_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['team_lead_id', 'agent_id', 'campaign_id'], 'atl_unique_assignment');
            $table->index(['campaign_id', 'agent_id'], 'atl_campaign_agent_idx');
            $table->index(['team_lead_id', 'campaign_id'], 'atl_tl_campaign_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_team_lead');
    }
};
