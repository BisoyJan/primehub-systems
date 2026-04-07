<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProcessorSpecRequest;
use App\Http\Traits\RedirectsWithFlashMessages;
use App\Models\ProcessorSpec;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ProcessorSpecsController extends Controller
{
    use RedirectsWithFlashMessages;

    public function index(Request $request)
    {
        $search = $request->input('search');

        $processorspecs = ProcessorSpec::search($search)
            ->latest()
            ->paginate(10)
            ->withQueryString();

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
            ProcessorSpec::create($request->validated());

            return $this->redirectWithFlash('processorspecs.index', 'Processor specification created successfully.');
        } catch (\Exception $e) {
            Log::error('ProcessorSpec Store Error: '.$e->getMessage());

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
            Log::error('ProcessorSpec Update Error: '.$e->getMessage());

            return $this->redirectWithFlash('processorspecs.index', 'Failed to update processor specification.', 'error');
        }
    }

    public function destroy(ProcessorSpec $processorspec)
    {
        if ($processorspec->pcSpecs()->count() > 0) {
            return $this->redirectWithFlash('processorspecs.index', 'Cannot delete this processor specification because it is currently assigned to one or more PC specs.', 'error');
        }

        try {
            $processorspec->delete();

            return $this->redirectWithFlash('processorspecs.index', 'Processor specification deleted successfully.');
        } catch (\Exception $e) {
            Log::error('ProcessorSpec Delete Error: '.$e->getMessage());

            return $this->redirectWithFlash('processorspecs.index', 'Failed to delete processor specification.', 'error');
        }
    }
}
