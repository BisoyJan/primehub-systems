<?php

namespace App\Http\Controllers;

use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;

class SiteController extends Controller
{
    // Display a paginated, searchable listing of sites
    public function index(Request $request)
    {
        $search = $request->query('search');
        $perPage = 10;

        $query = Site::query();
        if ($search) {
            $query->where('name', 'like', "%{$search}%");
        }

        $paginated = $query->orderBy('id', 'desc')
            ->paginate($perPage)
            ->withQueryString();

        $items = $paginated->getCollection()->map(function (Site $site) {
            return [
                'id' => $site->id,
                'name' => $site->name,
                'created_at' => optional($site->created_at)->toDateTimeString(),
                'updated_at' => optional($site->updated_at)->toDateTimeString(),
            ];
        })->toArray();

        $paginatorArray = $paginated->toArray();
        $links = $paginatorArray['links'] ?? [];

        return Inertia::render('Station/Site/Index', [
            'sites' => [
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

    // Show create form
    public function create()
    {
        return Inertia::render('Station/Site/Index');
    }

    // Store a newly created site
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
        ]);
        Site::create($data);
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
    public function update(Request $request, Site $site)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
        ]);
        $site->update($data);
        return redirect()->back()->with('flash', ['message' => 'Site updated', 'type' => 'success']);
    }

    // Remove the specified site
    public function destroy(Site $site)
    {
        $site->delete();
        return redirect()->back()->with('flash', ['message' => 'Site deleted', 'type' => 'success']);
    }
}
