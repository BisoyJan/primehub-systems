<?php

namespace App\Http\Controllers\Station;

use App\Http\Controllers\Controller;
use App\Models\Site;
use Illuminate\Http\Request;
use App\Http\Requests\SiteRequest;
use Inertia\Inertia;

class SiteController extends Controller
{
    // Display a paginated, searchable listing of sites
    public function index(Request $request)
    {
        $paginated = Site::query()
            ->search($request->query('search'))
            ->orderBy('id', 'desc')
            ->paginate(10)
            ->withQueryString();

        $items = $paginated->getCollection()->map(fn(Site $site) => [
            'id' => $site->id,
            'name' => $site->name,
            'created_at' => optional($site->created_at)->toDateTimeString(),
            'updated_at' => optional($site->updated_at)->toDateTimeString(),
        ])->toArray();

        return Inertia::render('Station/Site/Index', [
            'sites' => [
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

    // Show create form
    public function create()
    {
        return Inertia::render('Station/Site/Index');
    }

    // Store a newly created site
    public function store(SiteRequest $request)
    {
        Site::create($request->validated());
        return redirect()->back()->with('flash', ['message' => 'Site saved', 'type' => 'success']);
    }

    // Display the specified site
    public function show(Site $site)
    {
        return response()->json($site);
    }

    // Show edit form
    public function edit(Site $site)
    {
        return Inertia::render('Station/Site/Index', [
            'site' => $site,
        ]);
    }

    // Update the specified site
    public function update(SiteRequest $request, Site $site)
    {
        $site->update($request->validated());
        return redirect()->back()->with('flash', ['message' => 'Site updated', 'type' => 'success']);
    }

    // Remove the specified site
    public function destroy(Site $site)
    {
        $site->delete();
        return redirect()->back()->with('flash', ['message' => 'Site deleted', 'type' => 'success']);
    }

    // Private helper methods
}
