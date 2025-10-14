<?php

namespace App\Http\Controllers;

use App\Models\DiskSpec;
use App\Models\Stock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class DiskSpecsController extends Controller
{
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

    public function store(Request $request)
    {
        $validated = $this->validateDiskSpec($request, true);

        try {
            DB::transaction(function () use ($validated) {
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

    public function update(Request $request, DiskSpec $diskspec)
    {
        $validated = $this->validateDiskSpec($request);

        try {
            $diskspec->update($validated);
            Log::info('DiskSpec updated:', $validated);
            return $this->redirectWithFlash('diskspecs.index', 'Disk specification updated successfully.');
        } catch (\Exception $e) {
            Log::error('DiskSpec Update Error: ' . $e->getMessage());
            return $this->redirectWithFlash('diskspecs.index', 'Failed to update disk specification.', 'error');
        }
    }

    public function destroy(DiskSpec $diskspec)
    {
        // Check if there's stock
        if ($diskspec->stock && $diskspec->stock->quantity > 0) {
            return $this->redirectWithFlash(
                'diskspecs.index',
                'Cannot delete disk specification. It has ' . $diskspec->stock->quantity . ' units in stock. Please remove or transfer the stock first.',
                'error'
            );
        }

        // Check if it's being used in any PC specs
        $pcSpecCount = $diskspec->pcSpecs()->count();
        if ($pcSpecCount > 0) {
            return $this->redirectWithFlash(
                'diskspecs.index',
                'Cannot delete disk specification. It is being used in ' . $pcSpecCount . ' PC specification(s).',
                'error'
            );
        }

        try {
            // Delete the stock record if it exists
            if ($diskspec->stock) {
                $diskspec->stock->delete();
            }

            $diskspec->delete();
            return $this->redirectWithFlash('diskspecs.index', 'Disk specification deleted successfully.');
        } catch (\Exception $e) {
            Log::error('DiskSpec Delete Error: ' . $e->getMessage());
            return $this->redirectWithFlash('diskspecs.index', 'Failed to delete disk specification.', 'error');
        }
    }

    // Private helper methods
    private function validateDiskSpec(Request $request, bool $includeStock = false): array
    {
        $rules = [
            'manufacturer' => 'required|string|max:255',
            'model' => 'required|string|max:255',
            'capacity_gb' => 'required|integer|min:1',
            'interface' => 'required|string|max:255',
            'drive_type' => 'required|string|max:255',
            'sequential_read_mb' => 'required|integer|min:1',
            'sequential_write_mb' => 'required|integer|min:1',
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
