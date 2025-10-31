<?php

namespace App\Http\Controllers\Station;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use Illuminate\Http\Request;
use App\Http\Requests\CampaignRequest;
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

        $items = $paginated->getCollection()->map(fn(Campaign $campaign) => [
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

    // Private helper methods
}
