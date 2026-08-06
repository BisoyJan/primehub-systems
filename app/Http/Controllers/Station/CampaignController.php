<?php

namespace App\Http\Controllers\Station;

use App\Http\Controllers\Controller;
use App\Http\Requests\CampaignRequest;
use App\Models\Campaign;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class CampaignController extends Controller
{
    // Paginated, searchable index
    public function index(Request $request)
    {
        $paginated = Campaign::query()
            ->search($request->query('search'))
            ->orderBy('id', 'desc')
            ->paginate(10)
            ->withQueryString();

        $items = $paginated->getCollection()->map(fn (Campaign $campaign) => [
            'id' => $campaign->id,
            'name' => $campaign->name,
            'created_at' => optional($campaign->created_at)->toDateTimeString(),
            'updated_at' => optional($campaign->updated_at)->toDateTimeString(),
        ])->toArray();

        return Inertia::render('Station/Campaigns/Index', [
            'campaigns' => [
                'data' => $items,
                'links' => $paginated->toArray()['links'] ?? [],
                'meta' => [
                    'current_page' => $paginated->currentPage(),
                    'last_page' => $paginated->lastPage(),
                    'per_page' => $paginated->perPage(),
                    'total' => $paginated->total(),
                ],
            ],
            'flash' => session('flash') ?? null,
        ]);
    }

    // Store a newly created campaign
    public function store(CampaignRequest $request)
    {
        Campaign::create($request->validated());

        return redirect()->back()->with('flash', ['message' => 'Campaign saved', 'type' => 'success']);
    }

    // Update the specified campaign
    public function update(CampaignRequest $request, Campaign $campaign)
    {
        $campaign->update($request->validated());

        return redirect()->back()->with('flash', ['message' => 'Campaign updated', 'type' => 'success']);
    }

    // Remove the specified campaign
    public function destroy(Campaign $campaign)
    {
        $campaign->delete();

        return redirect()->back()->with('flash', ['message' => 'Campaign deleted', 'type' => 'success']);
    }

    /**
     * Return team lead/agent assignment data for a specific campaign.
     */
    public function teamAssignments(Campaign $campaign): JsonResponse
    {
        $teamLeads = User::where('role', 'Team Lead')
            ->where('is_approved', true)
            ->where('is_active', true)
            ->whereHas('campaigns', fn ($query) => $query->where('campaigns.id', $campaign->id))
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name'])
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
            ])
            ->values();

        $agents = User::where('role', 'Agent')
            ->where('is_approved', true)
            ->where('is_active', true)
            ->whereHas('activeSchedule', fn ($query) => $query->where('campaign_id', $campaign->id))
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name'])
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
            ])
            ->values();

        $assignmentRows = DB::table('agent_team_lead')
            ->where('campaign_id', $campaign->id)
            ->select(['team_lead_id', 'agent_id'])
            ->get();

        $assignments = $assignmentRows
            ->groupBy('team_lead_id')
            ->map(fn ($rows) => collect($rows)->pluck('agent_id')->map(fn ($id) => (int) $id)->values()->all());

        return response()->json([
            'campaign' => [
                'id' => $campaign->id,
                'name' => $campaign->name,
            ],
            'teamLeads' => $teamLeads,
            'agents' => $agents,
            'assignments' => $assignments,
        ]);
    }

    /**
     * Update team lead/agent assignments for a campaign.
     */
    public function updateTeamAssignments(Request $request, Campaign $campaign)
    {
        $validated = $request->validate([
            'assignments' => ['nullable', 'array'],
            'assignments.*' => ['nullable', 'array'],
            'assignments.*.*' => ['integer'],
        ]);

        $teamLeadIds = User::where('role', 'Team Lead')
            ->where('is_approved', true)
            ->where('is_active', true)
            ->whereHas('campaigns', fn ($query) => $query->where('campaigns.id', $campaign->id))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $agentIds = User::where('role', 'Agent')
            ->where('is_approved', true)
            ->where('is_active', true)
            ->whereHas('activeSchedule', fn ($query) => $query->where('campaign_id', $campaign->id))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $teamLeadIdSet = array_flip($teamLeadIds);
        $agentIdSet = array_flip($agentIds);

        $rowsToInsert = [];
        foreach (($validated['assignments'] ?? []) as $teamLeadId => $assignedAgentIds) {
            $teamLeadId = (int) $teamLeadId;
            if (! isset($teamLeadIdSet[$teamLeadId])) {
                continue;
            }

            foreach ((array) $assignedAgentIds as $agentId) {
                $agentId = (int) $agentId;
                if (! isset($agentIdSet[$agentId])) {
                    continue;
                }

                $rowsToInsert[$teamLeadId.':'.$agentId] = [
                    'team_lead_id' => $teamLeadId,
                    'agent_id' => $agentId,
                    'campaign_id' => $campaign->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        DB::transaction(function () use ($campaign, $rowsToInsert) {
            DB::table('agent_team_lead')
                ->where('campaign_id', $campaign->id)
                ->delete();

            if (! empty($rowsToInsert)) {
                DB::table('agent_team_lead')->insert(array_values($rowsToInsert));
            }
        });

        return redirect()->back()->with('flash', [
            'message' => 'Team assignments updated',
            'type' => 'success',
        ]);
    }

    // Private helper methods
}
