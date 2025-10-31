<?php

namespace App\Http\Controllers;

use App\Models\DiskSpec;
use App\Http\Requests\DiskSpecRequest;
use App\Http\Traits\HandlesStockOperations;
use App\Http\Traits\RedirectsWithFlashMessages;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class DiskSpecsController extends Controller
{
    use HandlesStockOperations, RedirectsWithFlashMessages;

    public function index(Request $request)
    {
        $search = $request->input('search');

        $diskspecs = DiskSpec::with('stock')
            ->search($search)
            ->latest()
            ->paginate(10)
            ->appends(['search' => $search]);

        return inertia('Computer/DiskSpecs/Index', [
            'diskspecs' => $diskspecs,
            'search' => $search,
        ]);
    }

    public function create()
    {
        return inertia('Computer/DiskSpecs/Create');
    }

    public function store(DiskSpecRequest $request)
    {
        try {
            DB::transaction(function () use ($request) {
                $validated = $request->validated();
                $stockQuantity = $validated['stock_quantity'];
                unset($validated['stock_quantity']);

                $diskSpec = DiskSpec::create($validated);
                $this->createStock($diskSpec, $stockQuantity);
            });

            return $this->redirectWithFlash('diskspecs.index', 'Disk specification created successfully.');
        } catch (\Exception $e) {
            Log::error('DiskSpec Store Error: ' . $e->getMessage());
            return $this->redirectWithFlash('diskspecs.index', 'Failed to create disk specification.', 'error');
        }
    }

    public function edit(string $diskspec)
    {
        return inertia('Computer/DiskSpecs/Edit', [
            'diskspec' => DiskSpec::findOrFail($diskspec),
        ]);
    }

    public function update(DiskSpecRequest $request, DiskSpec $diskspec)
    {
        try {
            $diskspec->update($request->validated());
            Log::info('DiskSpec updated:', $request->validated());
            return $this->redirectWithFlash('diskspecs.index', 'Disk specification updated successfully.');
        } catch (\Exception $e) {
            Log::error('DiskSpec Update Error: ' . $e->getMessage());
            return $this->redirectWithFlash('diskspecs.index', 'Failed to update disk specification.', 'error');
        }
    }

    public function destroy(DiskSpec $diskspec)
    {
        // Check if spec can be deleted
        if ($error = $this->canDeleteSpec($diskspec, 'disk specification')) {
            return $this->redirectWithFlash('diskspecs.index', $error['message'], $error['type']);
        }

        try {
            $this->deleteSpecWithStock($diskspec);
            return $this->redirectWithFlash('diskspecs.index', 'Disk specification deleted successfully.');
        } catch (\Exception $e) {
            Log::error('DiskSpec Delete Error: ' . $e->getMessage());
            return $this->redirectWithFlash('diskspecs.index', 'Failed to delete disk specification.', 'error');
        }
    }
}
