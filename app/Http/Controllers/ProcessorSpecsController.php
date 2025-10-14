<?php

namespace App\Http\Controllers;

use App\Models\ProcessorSpec;
use App\Models\Stock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class ProcessorSpecsController extends Controller
{
    public function index(Request $request)
    {
        $search = $request->input('search');

        $processorspecs = ProcessorSpec::with('stock')
            ->search($search)
            ->latest()
            ->paginate(10)
            ->appends(['search' => $search]);

        return inertia('Computer/ProcessorSpecs/Index', [
            'processorspecs' => $processorspecs,
            'search' => $search,
        ]);
    }

    public function create()
    {
        return inertia('Computer/ProcessorSpecs/Create');
    }

    public function store(Request $request)
    {
        $validated = $this->validateProcessorSpec($request, true);

        try {
            DB::transaction(function () use ($validated) {
                $stockQuantity = $validated['stock_quantity'];
                unset($validated['stock_quantity']);

                $processorSpec = ProcessorSpec::create($validated);
                $this->createStock($processorSpec, $stockQuantity);
            });

            return $this->redirectWithFlash('processorspecs.index', 'Processor specification created successfully.');
        } catch (\Exception $e) {
            Log::error('ProcessorSpec Store Error: ' . $e->getMessage());
            return $this->redirectWithFlash('processorspecs.index', 'Failed to create processor specification.', 'error');
        }
    }

    public function edit(string $processorspec)
    {
        return inertia('Computer/ProcessorSpecs/Edit', [
            'processorspec' => ProcessorSpec::findOrFail($processorspec),
        ]);
    }

    public function update(Request $request, ProcessorSpec $processorspec)
    {
        $validated = $this->validateProcessorSpec($request);

        try {
            $processorspec->update($validated);
            Log::info('ProcessorSpec Updated:', $validated);
            return $this->redirectWithFlash('processorspecs.index', 'Processor specification updated successfully.');
        } catch (\Exception $e) {
            Log::error('ProcessorSpec Update Error: ' . $e->getMessage());
            return $this->redirectWithFlash('processorspecs.index', 'Failed to update processor specification.', 'error');
        }
    }

    public function destroy(ProcessorSpec $processorspec)
    {
        // Check if there's stock
        if ($processorspec->stock && $processorspec->stock->quantity > 0) {
            return $this->redirectWithFlash(
                'processorspecs.index',
                'Cannot delete processor specification. It has ' . $processorspec->stock->quantity . ' units in stock. Please remove or transfer the stock first.',
                'error'
            );
        }

        // Check if it's being used in any PC specs
        $pcSpecCount = $processorspec->pcSpecs()->count();
        if ($pcSpecCount > 0) {
            return $this->redirectWithFlash(
                'processorspecs.index',
                'Cannot delete processor specification. It is being used in ' . $pcSpecCount . ' PC specification(s).',
                'error'
            );
        }

        try {
            // Delete the stock record if it exists
            if ($processorspec->stock) {
                $processorspec->stock->delete();
            }

            $processorspec->delete();
            return $this->redirectWithFlash('processorspecs.index', 'Processor specification deleted successfully.');
        } catch (\Exception $e) {
            Log::error('ProcessorSpec Delete Error: ' . $e->getMessage());
            return $this->redirectWithFlash('processorspecs.index', 'Failed to delete processor specification.', 'error');
        }
    }

    // Private helper methods
    private function validateProcessorSpec(Request $request, bool $includeStock = false): array
    {
        $rules = [
            'manufacturer' => 'required|string|max:255',
            'model' => 'required|string|max:255',
            'socket_type' => 'required|string|max:255',
            'core_count' => 'required|integer|min:1',
            'thread_count' => 'required|integer|min:1',
            'base_clock_ghz' => 'required|numeric|min:0',
            'boost_clock_ghz' => 'nullable|numeric|min:0',
            'integrated_graphics' => 'nullable|string|max:255',
            'tdp_watts' => 'nullable|integer|min:1',
        ];

        if ($includeStock) {
            $rules['stock_quantity'] = 'required|integer|min:0';
        }

        return $request->validate($rules);
    }

    private function createStock($model, int $quantity): void
    {
        Stock::create([
            'stockable_type' => get_class($model),
            'stockable_id' => $model->id,
            'quantity' => $quantity,
            'reserved' => 0,
        ]);
    }

    private function redirectWithFlash(string $route, string $message, string $type = 'success')
    {
        return redirect()
            ->route($route)
            ->with('message', $message)
            ->with('type', $type);
    }
}
