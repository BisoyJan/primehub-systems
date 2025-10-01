<?php

namespace App\Http\Controllers;

use App\Models\RamSpec;
use Illuminate\Http\Request;

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

        $ramSpecs = $query->paginate(10)->appends(['search' => $search]);
        return inertia('RamSpecs/Index', [
            'ramSpecs' => $ramSpecs,
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

        RamSpec::create($validated);

        return redirect()->route('ramspecs.index')->with('message', 'RAM specification created successfully.');
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
    public function edit(string $ramSpec)
    {
        return inertia('RamSpecs/Edit', [
            'ramSpec' => RamSpec::findOrFail($ramSpec)
        ]);

        //dd(RamSpec::findOrFail($ramspec));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, RamSpec $ramSpec)
    {
        $request->validate([
            'manufacturer' => 'required|string|max:255',
            'model' => 'required|string|max:255',
            'capacity_gb' => 'required|integer|min:1',
            'type' => 'required|string|max:255',
            'speed' => 'required|integer|min:1',
            'form_factor' => 'required|string|max:255',
            'voltage' => 'required|numeric|min:1',
        ]);

        $ramSpec->update($request->all());
        return redirect()->route('ramspecs.index')->with('message', 'RAM specification updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(RamSpec $ramSpec)
    {
        $ramSpec->delete();
        return redirect()->route('ramspecs.index')->with('message', 'RAM specification deleted successfully.');
    }
}
