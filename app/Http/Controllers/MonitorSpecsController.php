<?php

namespace App\Http\Controllers;

use App\Models\MonitorSpec;
use App\Http\Requests\StoreMonitorSpecRequest;
use App\Http\Requests\UpdateMonitorSpecRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MonitorSpecsController extends Controller
{
    public function index(Request $request)
    {
        $query = MonitorSpec::query()->with('stock');

        // Search functionality
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('brand', 'like', "%{$search}%")
                    ->orWhere('model', 'like', "%{$search}%")
                    ->orWhere('resolution', 'like', "%{$search}%")
                    ->orWhere('panel_type', 'like', "%{$search}%");
            });
        }

        $monitorspecs = $query->latest()
            ->paginate(10)
            ->appends(['search' => $request->input('search')]);

        return inertia('Computer/MonitorSpecs/Index', [
            'monitorspecs' => $monitorspecs,
            'search' => $request->input('search'),
        ]);
    }

    public function create()
    {
        return inertia('Computer/MonitorSpecs/Form', [
            'monitorspec' => null,
        ]);
    }

    public function store(StoreMonitorSpecRequest $request)
    {
        try {
            MonitorSpec::create($request->validated());

            return redirect()
                ->route('monitorspecs.index')
                ->with('message', 'Monitor specification created successfully.')
                ->with('type', 'success');
        } catch (\Exception $e) {
            Log::error('MonitorSpec Store Error: ' . $e->getMessage());
            return redirect()
                ->route('monitorspecs.index')
                ->with('message', 'Failed to create monitor specification.')
                ->with('type', 'error');
        }
    }

    public function edit(MonitorSpec $monitorspec)
    {
        return inertia('Computer/MonitorSpecs/Form', [
            'monitorspec' => $monitorspec,
        ]);
    }

    public function update(UpdateMonitorSpecRequest $request, MonitorSpec $monitorspec)
    {
        try {
            $monitorspec->update($request->validated());

            return redirect()
                ->route('monitorspecs.index')
                ->with('message', 'Monitor specification updated successfully.')
                ->with('type', 'success');
        } catch (\Exception $e) {
            Log::error('MonitorSpec Update Error: ' . $e->getMessage());
            return redirect()
                ->route('monitorspecs.index')
                ->with('message', 'Failed to update monitor specification.')
                ->with('type', 'error');
        }
    }

    public function destroy(MonitorSpec $monitorspec)
    {
        // Check if there's stock
        if ($monitorspec->stock && $monitorspec->stock->quantity > 0) {
            return redirect()
                ->route('monitorspecs.index')
                ->with('message', "Cannot delete monitor specification. It has {$monitorspec->stock->quantity} units in stock. Please remove or transfer the stock first.")
                ->with('type', 'error');
        }

        // Check if it's being used in any PC specs
        $pcSpecCount = $monitorspec->pcSpecs()->count();
        if ($pcSpecCount > 0) {
            return redirect()
                ->route('monitorspecs.index')
                ->with('message', "Cannot delete monitor specification. It is being used in {$pcSpecCount} PC specification(s).")
                ->with('type', 'error');
        }

        // Check if it's being used in any stations
        $stationCount = $monitorspec->stations()->count();
        if ($stationCount > 0) {
            return redirect()
                ->route('monitorspecs.index')
                ->with('message', "Cannot delete monitor specification. It is assigned to {$stationCount} station(s).")
                ->with('type', 'error');
        }

        try {
            // Delete the stock record if it exists
            if ($monitorspec->stock) {
                $monitorspec->stock->delete();
            }

            $monitorspec->delete();

            return redirect()
                ->route('monitorspecs.index')
                ->with('message', 'Monitor specification deleted successfully.')
                ->with('type', 'success');
        } catch (\Exception $e) {
            Log::error('MonitorSpec Delete Error: ' . $e->getMessage());
            return redirect()
                ->route('monitorspecs.index')
                ->with('message', 'Failed to delete monitor specification.')
                ->with('type', 'error');
        }
    }
}
