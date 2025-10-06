<?php

namespace App\Http\Controllers;

use App\Models\ProcessorSpec;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ProcessorSpecsController extends Controller
{
    /**
     * Display a paginated listing of processor specs.
     */
    public function index(Request $request)
    {
        $query = ProcessorSpec::with('stock');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('brand', 'like', "%{$search}%")
                    ->orWhere('series', 'like', "%{$search}%");
            });
        }

        $processorspecs = $query
            ->latest()
            ->paginate(10)
            ->appends(['search' => $search]);

        return inertia('ProcessorSpecs/Index', [
            'processorspecs' => $processorspecs,
            'search'         => $search,
        ]);
    }

    /**
     * Show the form for creating a new processor spec.
     */
    public function create()
    {
        return inertia('ProcessorSpecs/Create');
    }

    /**
     * Store a newly created processor spec.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'brand'               => 'required|string|max:255',
            'series'              => 'required|string|max:255',
            'socket_type'         => 'required|string|max:255',
            'core_count'          => 'required|integer|min:1',
            'thread_count'        => 'required|integer|min:1',
            'base_clock_ghz'      => 'required|numeric|min:0',
            'boost_clock_ghz'     => 'nullable|numeric|min:0',
            'integrated_graphics' => 'nullable|string|max:255',
            'tdp_watts'           => 'nullable|integer|min:1',
        ]);

        try {
            ProcessorSpec::create($validated);

            return redirect()
                ->route('processorspecs.index')
                ->with('message', 'Processor specification created successfully.')
                ->with('type', 'success');
        } catch (\Exception $e) {
            Log::error('ProcessorSpec Store Error: ' . $e->getMessage());

            return redirect()
                ->route('processorspecs.index')
                ->with('message', 'Failed to create processor specification.')
                ->with('type', 'error');
        }
    }

    /**
     * Show the form for editing the specified processor spec.
     */
    public function edit(string $processorspec)
    {
        return inertia('ProcessorSpecs/Edit', [
            'processorspec' => ProcessorSpec::findOrFail($processorspec),
        ]);
    }

    /**
     * Update the specified processor spec.
     */
    public function update(Request $request, ProcessorSpec $processorspec)
    {
        $validated = $request->validate([
            'brand'               => 'required|string|max:255',
            'series'              => 'required|string|max:255',
            'socket_type'         => 'required|string|max:255',
            'core_count'          => 'required|integer|min:1',
            'thread_count'        => 'required|integer|min:1',
            'base_clock_ghz'      => 'required|numeric|min:0',
            'boost_clock_ghz'     => 'nullable|numeric|min:0',
            'integrated_graphics' => 'nullable|string|max:255',
            'tdp_watts'           => 'nullable|integer|min:1',
        ]);

        try {
            $processorspec->update($validated);
            Log::info('ProcessorSpec Updated:', $validated);

            return redirect()
                ->route('processorspecs.index')
                ->with('message', 'Processor specification updated successfully.')
                ->with('type', 'success');
        } catch (\Exception $e) {
            Log::error('ProcessorSpec Update Error: ' . $e->getMessage());

            return redirect()
                ->route('processorspecs.index')
                ->with('message', 'Failed to update processor specification.')
                ->with('type', 'error');
        }
    }

    /**
     * Remove the specified processor spec.
     */
    public function destroy(ProcessorSpec $processorspec)
    {
        try {
            $processorspec->delete();

            return redirect()
                ->route('processorspecs.index')
                ->with('message', 'Processor specification deleted successfully.')
                ->with('type', 'success');
        } catch (\Exception $e) {
            Log::error('ProcessorSpec Delete Error: ' . $e->getMessage());

            return redirect()
                ->route('processorspecs.index')
                ->with('message', 'Failed to delete processor specification.')
                ->with('type', 'error');
        }
    }
}
