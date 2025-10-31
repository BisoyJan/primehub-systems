<?php

namespace App\Http\Controllers;

use App\Models\RamSpec;
use App\Http\Requests\RamSpecRequest;
use App\Http\Traits\HandlesStockOperations;
use App\Http\Traits\RedirectsWithFlashMessages;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class RamSpecsController extends Controller
{
    use HandlesStockOperations, RedirectsWithFlashMessages;

    public function index(Request $request)
    {
        $ramspecs = RamSpec::with('stock')
            ->search($request->input('search'))
            ->latest()
            ->paginate(10)
            ->appends(['search' => $request->input('search')]);

        return inertia('Computer/RamSpecs/Index', [
            'ramspecs' => $ramspecs,
            'search' => $request->input('search'),
        ]);
    }

    public function create()
    {
        return inertia('Computer/RamSpecs/Create');
    }

    public function store(RamSpecRequest $request)
    {
        try {
            DB::transaction(function () use ($request) {
                $validated = $request->validated();
                $stockQuantity = $validated['stock_quantity'];
                unset($validated['stock_quantity']);

                $ramSpec = RamSpec::create($validated);
                $this->createStock($ramSpec, $stockQuantity);
            });

            return $this->redirectWithFlash('ramspecs.index', 'RAM specification created successfully.');
        } catch (\Exception $e) {
            Log::error('RamSpec Store Error: ' . $e->getMessage());
            return $this->redirectWithFlash('ramspecs.index', 'Failed to create RAM specification.', 'error');
        }
    }

    public function edit(string $ramspec)
    {
        return inertia('Computer/RamSpecs/Edit', [
            'ramspec' => RamSpec::findOrFail($ramspec)
        ]);
    }

    public function update(RamSpecRequest $request, RamSpec $ramspec)
    {
        try {
            $ramspec->update($request->validated());
            return $this->redirectWithFlash('ramspecs.index', 'RAM specification updated successfully.');
        } catch (\Exception $e) {
            Log::error('RamSpec Update Error: ' . $e->getMessage());
            return $this->redirectWithFlash('ramspecs.index', 'Failed to update RAM specification.', 'error');
        }
    }

    public function destroy(RamSpec $ramspec)
    {
        // Check if spec can be deleted
        if ($error = $this->canDeleteSpec($ramspec, 'RAM specification')) {
            return $this->redirectWithFlash('ramspecs.index', $error['message'], $error['type']);
        }

        try {
            $this->deleteSpecWithStock($ramspec);
            return $this->redirectWithFlash('ramspecs.index', 'RAM specification deleted successfully.');
        } catch (\Exception $e) {
            Log::error('RamSpec Delete Error: ' . $e->getMessage());
            return $this->redirectWithFlash('ramspecs.index', 'Failed to delete RAM specification.', 'error');
        }
    }
}
