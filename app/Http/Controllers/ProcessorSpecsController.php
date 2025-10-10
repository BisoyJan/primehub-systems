<?php

namespace App\Http\Controllers;

use App\Models\ProcessorSpec;
use App\Models\Stock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

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
                $q->where('manufacturer', 'like', "%{$search}%")
                    ->orWhere('model', 'like', "%{$search}%");
            });
        }

        $processorspecs = $query
            ->latest()
            ->paginate(10)
            ->appends(['search' => $search]);

        return inertia('Computer/ProcessorSpecs/Index', [
            'processorspecs' => $processorspecs,
            'search'         => $search,
        ]);
    }

    /**
     * Show the form for creating a new processor spec.
     */
    public function create()
    {
        return inertia('Computer/ProcessorSpecs/Create');
    }

    /**
     * Store a newly created processor spec.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'manufacturer'               => 'required|string|max:255',
            'model'              => 'required|string|max:255',
            'socket_type'         => 'required|string|max:255',
            'core_count'          => 'required|integer|min:1',
            'thread_count'        => 'required|integer|min:1',
            'base_clock_ghz'      => 'required|numeric|min:0',
            'boost_clock_ghz'     => 'nullable|numeric|min:0',
            'integrated_graphics' => 'nullable|string|max:255',
            'tdp_watts'           => 'nullable|integer|min:1',
            'stock_quantity'      => 'required|integer|min:0', // Added stock quantity validation
        ]);

        try {
            DB::transaction(function () use ($validated) {
                // Extract stock_quantity from validated data
                $stockQuantity = $validated['stock_quantity'];
                unset($validated['stock_quantity']);

                // Create the processor spec
                $processorSpec = ProcessorSpec::create($validated);

                // Create the stock entry
                Stock::create([
                    'stockable_type' => ProcessorSpec::class,
                    'stockable_id' => $processorSpec->id,
                    'quantity' => $stockQuantity,
                    'reserved' => 0,
                ]);
            });

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
        return inertia('Computer/ProcessorSpecs/Edit', [
            'processorspec' => ProcessorSpec::findOrFail($processorspec),
        ]);
    }

    /**
     * Update the specified processor spec.
     */
    public function update(Request $request, ProcessorSpec $processorspec)
    {
        $validated = $request->validate([
            'manufacturer'               => 'required|string|max:255',
            'model'              => 'required|string|max:255',
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
