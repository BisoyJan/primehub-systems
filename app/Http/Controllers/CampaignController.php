<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CampaignController extends Controller
{
    // Paginated, searchable index
    public function index(Request $request)
    {
        $search = $request->query('search');
        $perPage = 10;

        $query = Campaign::query();
        if ($search) {
            $query->where('name', 'like', "%{$search}%");
        }

        $paginated = $query->orderBy('id', 'desc')
            ->paginate($perPage)
            ->withQueryString();

        $items = $paginated->getCollection()->map(function (Campaign $campaign) {
            return [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'created_at' => optional($campaign->created_at)->toDateTimeString(),
                'updated_at' => optional($campaign->updated_at)->toDateTimeString(),
            ];
        })->toArray();

        $paginatorArray = $paginated->toArray();
        $links = $paginatorArray['links'] ?? [];

        return Inertia::render('Station/Campaigns/Index', [
            'campaigns' => [
                'data' => $items,
                'links' => $links,
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
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
        ]);
        Campaign::create($data);
        return redirect()->back()->with('flash', ['message' => 'Campaign saved', 'type' => 'success']);
    }

    // Update the specified campaign
    public function update(Request $request, Campaign $campaign)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
        ]);
        $campaign->update($data);
        return redirect()->back()->with('flash', ['message' => 'Campaign updated', 'type' => 'success']);
    }

    // Remove the specified campaign
    public function destroy(Campaign $campaign)
    {
        $campaign->delete();
        return redirect()->back()->with('flash', ['message' => 'Campaign deleted', 'type' => 'success']);
    }
}
