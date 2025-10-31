<?php

namespace App\Http\Controllers;

use App\Models\MonitorSpec;
use App\Http\Requests\MonitorSpecRequest;
use App\Http\Traits\HandlesStockOperations;
use App\Http\Traits\RedirectsWithFlashMessages;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MonitorSpecsController extends Controller
{
    use HandlesStockOperations, RedirectsWithFlashMessages;

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

    public function store(MonitorSpecRequest $request)
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

    public function update(MonitorSpecRequest $request, MonitorSpec $monitorspec)
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
            // Check if spec can be deleted
            if ($error = $this->canDeleteSpec($monitorspec, 'monitor specification')) {
                return $this->redirectWithFlash('monitorspecs.index', $error['message'], $error['type']);
        }

        try {
                $this->deleteSpecWithStock($monitorspec);
                return $this->redirectWithFlash('monitorspecs.index', 'Monitor specification deleted successfully.');
        } catch (\Exception $e) {
            Log::error('MonitorSpec Delete Error: ' . $e->getMessage());
                return $this->redirectWithFlash('monitorspecs.index', 'Failed to delete monitor specification.', 'error');
        }
    }
}
