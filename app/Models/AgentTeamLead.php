<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AgentTeamLead extends Model
{
    use HasFactory;

    protected $table = 'agent_team_lead';

    protected $fillable = [
        'team_lead_id',
        'agent_id',
        'campaign_id',
    ];
}
