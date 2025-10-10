<?php

namespace App\Http\Controllers;

use App\Models\DiskSpec;
use App\Models\Stock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class DiskSpecsController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = DiskSpec::with('stock');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('manufacturer', 'like', "%{$search}%")
                    ->orWhere('model', 'like', "%{$search}%")
                    ->orWhere('interface', 'like', "%{$search}%")
                    ->orWhere('drive_type', 'like', "%{$search}%")
                    ->orWhere('capacity_gb', 'like', "%{$search}%");
            });
        }

        $diskspecs = $query
            ->latest()
            ->paginate(10)
            ->appends(['search' => $search]);

        return inertia('Computer/DiskSpecs/Index', [
            'diskspecs' => $diskspecs,
            'search'    => $search,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return inertia('Computer/DiskSpecs/Create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'manufacturer'         => 'required|string|max:255',
            'model'         => 'required|string|max:255',
            'capacity_gb'          => 'required|integer|min:1',
            'interface'            => 'required|string|max:255',
            'drive_type'           => 'required|string|max:255',
            'sequential_read_mb'   => 'required|integer|min:1',
            'sequential_write_mb'  => 'required|integer|min:1',
            'stock_quantity'       => 'required|integer|min:0', // Added stock quantity validation
        ]);

        try {
            DB::transaction(function () use ($validated) {
                // Extract stock_quantity from validated data
                $stockQuantity = $validated['stock_quantity'];
                unset($validated['stock_quantity']);

                // Create the disk spec
                $diskSpec = DiskSpec::create($validated);

                // Create the stock entry
                Stock::create([
                    'stockable_type' => DiskSpec::class,
                    'stockable_id' => $diskSpec->id,
                    'quantity' => $stockQuantity,
                    'reserved' => 0,
                ]);
            });

            return redirect()
                ->route('diskspecs.index')
                ->with('message', 'Disk specification created successfully.')
                ->with('type', 'success');
        } catch (\Exception $e) {
            Log::error('DiskSpec Store Error: ' . $e->getMessage());

            return redirect()
                ->route('diskspecs.index')
                ->with('message', 'Failed to create disk specification.')
                ->with('type', 'error');
        }
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $diskspec)
    {
        return inertia('Computer/DiskSpecs/Edit', [
            'diskspec' => DiskSpec::findOrFail($diskspec),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, DiskSpec $diskspec)
    {
        $validated = $request->validate([
            'manufacturer'         => 'required|string|max:255',
            'model'         => 'required|string|max:255',
            'capacity_gb'          => 'required|integer|min:1',
            'interface'            => 'required|string|max:255',
            'drive_type'           => 'required|string|max:255',
            'sequential_read_mb'   => 'required|integer|min:1',
            'sequential_write_mb'  => 'required|integer|min:1',
        ]);

        try {
            $diskspec->update($validated);
            Log::info('DiskSpec updated:', $validated);

            return redirect()
                ->route('diskspecs.index')
                ->with('message', 'Disk specification updated successfully.')
                ->with('type', 'success');
        } catch (\Exception $e) {
            Log::error('DiskSpec Update Error: ' . $e->getMessage());

            return redirect()
                ->route('diskspecs.index')
                ->with('message', 'Failed to update disk specification.')
                ->with('type', 'error');
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(DiskSpec $diskspec)
    {
        try {
            $diskspec->delete();

            return redirect()
                ->route('diskspecs.index')
                ->with('message', 'Disk specification deleted successfully.')
                ->with('type', 'success');
        } catch (\Exception $e) {
            Log::error('DiskSpec Delete Error: ' . $e->getMessage());

            return redirect()
                ->route('diskspecs.index')
                ->with('message', 'Failed to delete disk specification.')
                ->with('type', 'error');
        }
    }
}
