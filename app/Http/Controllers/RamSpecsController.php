<?php

namespace App\Http\Controllers;

use App\Models\RamSpec;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class RamSpecsController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = RamSpec::query();

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('manufacturer', 'like', "%{$search}%")
                    ->orWhere('model', 'like', "%{$search}%")
                    ->orWhere('type', 'like', "%{$search}%")
                    ->orWhere('capacity_gb', 'like', "%{$search}%")
                    ->orWhere('form_factor', 'like', "%{$search}%");
            });
        }

        $ramspecs = $query
            ->latest()
            ->paginate(10)
            ->appends(['search' => $search]);
        return inertia('RamSpecs/Index', [
            'ramspecs' => $ramspecs,
            'search' => $search,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return inertia('RamSpecs/Create', []);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'manufacturer' => 'required|string|max:255',
            'model' => 'required|string|max:255',
            'capacity_gb' => 'required|integer|min:1',
            'type' => 'required|string|max:255',
            'speed' => 'required|integer|min:1',
            'form_factor' => 'required|string|max:255',
            'voltage' => 'required|numeric|min:0',
        ]);

        try {
            RamSpec::create($validated);
            return redirect()
                ->route('ramspecs.index')
                ->with('message', 'RAM specification created successfully.')
                ->with('type', 'success');
        } catch (\Exception $e) {
            Log::error('RamSpec Store Error: ' . $e->getMessage());
            return redirect()
                ->route('ramspecs.index')
                ->with('message', 'Failed to create RAM specification.')
                ->with('type', 'error');
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $ramspec)
    {
        return inertia('RamSpecs/Edit', [
            'ramspec' => RamSpec::findOrFail($ramspec)
        ]);

        //dd(RamSpec::findOrFail($ramspec));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, RamSpec $ramspec)
    {
        $validated = $request->validate([
            'manufacturer' => 'required|string|max:255',
            'model' => 'required|string|max:255',
            'capacity_gb' => 'required|integer|min:1',
            'type' => 'required|string|max:255',
            'speed' => 'required|integer|min:1',
            'form_factor' => 'required|string|max:255',
            'voltage' => 'required|numeric|min:1',
        ]);

        try {
            $ramspec->update($validated);

            return redirect()
                ->route('ramspecs.index')
                ->with('message', 'RAM specification updated successfully.')
                ->with('type', 'success');
        } catch (\Exception $e) {
            Log::error('RamSpec Update Error: ' . $e->getMessage());
            return redirect()
                ->route('ramspecs.index')
                ->with('message', 'Failed to update RAM specification.')
                ->with('type', 'error');
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(RamSpec $ramspec)
    {
        try {
            $ramspec->delete();
            return redirect()
                ->route('ramspecs.index')
                ->with('message', 'RAM specification deleted successfully.')
                ->with('type', 'success');
        } catch (\Exception $e) {
            Log::error('RamSpec Delete Error: ' . $e->getMessage());
            return redirect()
                ->route('ramspecs.index')
                ->with('message', 'Failed to delete RAM specification.')
                ->with('type', 'error');
        }
    }
}
