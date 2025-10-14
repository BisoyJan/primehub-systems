<?php

namespace App\Http\Controllers;

use App\Models\RamSpec;
use App\Models\Stock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class RamSpecsController extends Controller
{
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

    public function store(Request $request)
    {
        $validated = $this->validateRamSpec($request, true);

        try {
            DB::transaction(function () use ($validated) {
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

    public function update(Request $request, RamSpec $ramspec)
    {
        $validated = $this->validateRamSpec($request);

        try {
            $ramspec->update($validated);
            return $this->redirectWithFlash('ramspecs.index', 'RAM specification updated successfully.');
        } catch (\Exception $e) {
            Log::error('RamSpec Update Error: ' . $e->getMessage());
            return $this->redirectWithFlash('ramspecs.index', 'Failed to update RAM specification.', 'error');
        }
    }

    public function destroy(RamSpec $ramspec)
    {
        // Check if there's stock
        if ($ramspec->stock && $ramspec->stock->quantity > 0) {
            return $this->redirectWithFlash(
                'ramspecs.index',
                'Cannot delete RAM specification. It has ' . $ramspec->stock->quantity . ' units in stock. Please remove or transfer the stock first.',
                'error'
            );
        }

        // Check if it's being used in any PC specs
        $pcSpecCount = $ramspec->pcSpecs()->count();
        if ($pcSpecCount > 0) {
            return $this->redirectWithFlash(
                'ramspecs.index',
                'Cannot delete RAM specification. It is being used in ' . $pcSpecCount . ' PC specification(s).',
                'error'
            );
        }

        try {
            // Delete the stock record if it exists
            if ($ramspec->stock) {
                $ramspec->stock->delete();
            }

            $ramspec->delete();
            return $this->redirectWithFlash('ramspecs.index', 'RAM specification deleted successfully.');
        } catch (\Exception $e) {
            Log::error('RamSpec Delete Error: ' . $e->getMessage());
            return $this->redirectWithFlash('ramspecs.index', 'Failed to delete RAM specification.', 'error');
        }
    }

    // Private helper methods
    private function validateRamSpec(Request $request, bool $includeStock = false): array
    {
        $rules = [
            'manufacturer' => 'required|string|max:255',
            'model' => 'required|string|max:255',
            'capacity_gb' => 'required|integer|min:1',
            'type' => 'required|string|max:255',
            'speed' => 'required|integer|min:1',
            'form_factor' => 'required|string|max:255',
            'voltage' => 'required|numeric|min:0',
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
