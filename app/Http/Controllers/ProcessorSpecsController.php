<?php

namespace App\Http\Controllers;

use App\Models\ProcessorSpec;
use App\Http\Requests\ProcessorSpecRequest;
use App\Http\Traits\HandlesStockOperations;
use App\Http\Traits\RedirectsWithFlashMessages;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class ProcessorSpecsController extends Controller
{
    use HandlesStockOperations, RedirectsWithFlashMessages;

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

    public function store(ProcessorSpecRequest $request)
    {
        try {
            DB::transaction(function () use ($request) {
                $validated = $request->validated();
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

    public function update(ProcessorSpecRequest $request, ProcessorSpec $processorspec)
    {
        try {
            $processorspec->update($request->validated());
            Log::info('ProcessorSpec Updated:', $request->validated());
            return $this->redirectWithFlash('processorspecs.index', 'Processor specification updated successfully.');
        } catch (\Exception $e) {
            Log::error('ProcessorSpec Update Error: ' . $e->getMessage());
            return $this->redirectWithFlash('processorspecs.index', 'Failed to update processor specification.', 'error');
        }
    }

    public function destroy(ProcessorSpec $processorspec)
    {
        // Check if spec can be deleted
        if ($error = $this->canDeleteSpec($processorspec, 'processor specification')) {
            return $this->redirectWithFlash('processorspecs.index', $error['message'], $error['type']);
        }

        try {
            $this->deleteSpecWithStock($processorspec);
            return $this->redirectWithFlash('processorspecs.index', 'Processor specification deleted successfully.');
        } catch (\Exception $e) {
            Log::error('ProcessorSpec Delete Error: ' . $e->getMessage());
            return $this->redirectWithFlash('processorspecs.index', 'Failed to delete processor specification.', 'error');
        }
    }
}
