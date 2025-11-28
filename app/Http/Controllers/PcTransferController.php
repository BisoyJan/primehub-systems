<?php

namespace App\Http\Controllers;

use App\Models\Station;
use App\Models\PcSpec;
use App\Models\PcTransfer;
use App\Models\Site;
use App\Models\Campaign;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class PcTransferController extends Controller
{
    /**
     * Display the PC transfer interface
     */
    public function index(Request $request)
    {
        $search = $request->query('search');
        $siteFilter = $request->query('site');
        $campaignFilter = $request->query('campaign');
        $perPage = 15;

        // Get stations with their PC specs
        $query = Station::with(['site', 'campaign', 'pcSpec.ramSpecs', 'pcSpec.diskSpecs', 'pcSpec.processorSpecs'])
            ->orderBy('station_number', 'asc');

        // Apply filters
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('station_number', 'like', "%{$search}%")
                    ->orWhereHas('site', fn($sq) => $sq->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('campaign', fn($cq) => $cq->where('name', 'like', "%{$search}%"));
            });
        }

        if ($siteFilter && $siteFilter !== 'all') {
            $query->where('site_id', $siteFilter);
        }

        if ($campaignFilter && $campaignFilter !== 'all') {
            $query->where('campaign_id', $campaignFilter);
        }

        $paginated = $query->paginate($perPage)->withQueryString();

        // Format stations data
        $stations = $paginated->getCollection()->map(function ($station) {
            return [
                'id' => $station->id,
                'station_number' => $station->station_number,
                'site' => $station->site?->name,
                'site_id' => $station->site_id,
                'campaign' => $station->campaign?->name,
                'campaign_id' => $station->campaign_id,
                'status' => $station->status,
                'monitor_type' => $station->monitor_type,
                'pc_spec_id' => $station->pc_spec_id,
                'pc_spec_details' => $station->pcSpec ? $station->pcSpec->getFormattedDetails() : null,
            ];
        });

        $paginatorArray = $paginated->toArray();

        // Get all available PC specs for transfer
        $pcSpecs = PcSpec::with(['ramSpecs', 'diskSpecs', 'processorSpecs'])
            ->get()
            ->map(function ($pc) {
                return [
                    'id' => $pc->id,
                    'label' => $pc->model,
                    'details' => $pc->getFormattedDetails(),
                ];
            });

        return Inertia::render('Station/PcTransfer/Index', [
            'stations' => [
                'data' => $stations,
                'links' => $paginatorArray['links'] ?? [],
                'meta' => [
                    'current_page' => $paginated->currentPage(),
                    'last_page' => $paginated->lastPage(),
                    'per_page' => $paginated->perPage(),
                    'total' => $paginated->total(),
                ],
            ],
            'pcSpecs' => $pcSpecs,
            'filters' => [
                'sites' => Site::all(['id', 'name']),
                'campaigns' => Campaign::all(['id', 'name']),
            ],
            'flash' => session('flash') ?? null,
        ]);
    }

    /**
     * Transfer a PC to a station (can also handle swapping)
     */
    public function transfer(Request $request)
    {
        $validated = $request->validate([
            'to_station_id' => 'required|exists:stations,id',
            'pc_spec_id' => 'required|exists:pc_specs,id',
            'from_station_id' => 'nullable|exists:stations,id',
            'transfer_type' => 'required|in:assign,swap,remove',
            'notes' => 'nullable|string|max:500',
        ]);

        DB::beginTransaction();
        try {
            $toStation = Station::findOrFail($validated['to_station_id']);
            $pcSpec = PcSpec::findOrFail($validated['pc_spec_id']);

            // Find all stations currently assigned to this PC and remove it
            $currentStations = Station::where('pc_spec_id', $validated['pc_spec_id'])->get();
            $fromStation = $currentStations->first(); // Use first as primary source station

            // Handle swap if the target station already has a PC
            if ($toStation->pc_spec_id && $validated['transfer_type'] === 'swap' && $fromStation) {
                $tempPcId = $toStation->pc_spec_id;

                // Remove current PC from all its assigned stations
                foreach ($currentStations as $station) {
                    $station->pc_spec_id = null;
                    $station->save();
                }

                // Assign new PC to target station
                $toStation->pc_spec_id = $validated['pc_spec_id'];
                $toStation->save();

                // Assign replaced PC to source station
                $fromStation->pc_spec_id = $tempPcId;
                $fromStation->save();

                // Log both transfers
                PcTransfer::create([
                    'from_station_id' => $fromStation->id,
                    'to_station_id' => $toStation->id,
                    'pc_spec_id' => $validated['pc_spec_id'],
                    'user_id' => auth()->id(),
                    'transfer_type' => 'swap',
                    'notes' => $validated['notes'] ?? null,
                ]);

                PcTransfer::create([
                    'from_station_id' => $toStation->id,
                    'to_station_id' => $fromStation->id,
                    'pc_spec_id' => $tempPcId,
                    'user_id' => auth()->id(),
                    'transfer_type' => 'swap',
                    'notes' => $validated['notes'] ?? null,
                ]);
            } else {
                // Simple assign/transfer
                // Remove PC from all its currently assigned stations
                foreach ($currentStations as $station) {
                    $station->pc_spec_id = null;
                    $station->save();
                }

                // Assign PC to target station
                $toStation->pc_spec_id = $validated['pc_spec_id'];
                $toStation->save();

                // Log transfer
                PcTransfer::create([
                    'from_station_id' => $fromStation?->id,
                    'to_station_id' => $toStation->id,
                    'pc_spec_id' => $validated['pc_spec_id'],
                    'user_id' => auth()->id(),
                    'transfer_type' => $validated['transfer_type'],
                    'notes' => $validated['notes'] ?? null,
                ]);
            }

            DB::commit();

            return redirect()->back()->with('flash', [
                'message' => 'PC transferred successfully',
                'type' => 'success'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()->with('flash', [
                'message' => 'Transfer failed: ' . $e->getMessage(),
                'type' => 'error'
            ]);
        }
    }

    /**
     * Bulk transfer multiple PCs
     */
    public function bulkTransfer(Request $request)
    {
        $validated = $request->validate([
            'transfers' => 'required|array',
            'transfers.*.to_station_id' => 'required|exists:stations,id',
            'transfers.*.pc_spec_id' => 'required|exists:pc_specs,id',
            'transfers.*.from_station_id' => 'nullable|exists:stations,id',
            'notes' => 'nullable|string|max:500',
        ]);

        DB::beginTransaction();
        try {
            $floatingPcs = []; // Track PCs that become floating

            foreach ($validated['transfers'] as $transfer) {
                $toStation = Station::findOrFail($transfer['to_station_id']);
                $pcSpec = PcSpec::findOrFail($transfer['pc_spec_id']);

                // Find all stations currently assigned to this PC and remove it
                $currentStations = Station::where('pc_spec_id', $transfer['pc_spec_id'])->get();
                foreach ($currentStations as $currentStation) {
                    $currentStation->pc_spec_id = null;
                    $currentStation->save();
                }

                // If target station has a PC, it will become floating (unassigned)
                $replacedPcId = $toStation->pc_spec_id;
                if ($replacedPcId) {
                    $floatingPc = PcSpec::find($replacedPcId);

                    $floatingPcs[] = [
                        'pc_number' => $floatingPc->pc_number,
                        'model' => $floatingPc->model,
                        'from_station' => $toStation->station_number,
                    ];
                }

                // Assign PC to target station (this replaces any existing PC)
                $toStation->pc_spec_id = $transfer['pc_spec_id'];
                $toStation->save();

                // Log transfer
                PcTransfer::create([
                    'from_station_id' => $currentStations->first()?->id, // Log the first/primary station it came from
                    'to_station_id' => $toStation->id,
                    'pc_spec_id' => $transfer['pc_spec_id'],
                    'user_id' => auth()->id(),
                    'transfer_type' => 'assign',
                    'notes' => $validated['notes'] ?? null,
                ]);
            }

            DB::commit();

            $message = count($validated['transfers']) . ' PC(s) transferred successfully';
            if (count($floatingPcs) > 0) {
                $floatingList = collect($floatingPcs)->map(function ($pc) {
                    return $pc['pc_number'] . ' (from ' . $pc['from_station'] . ')';
                })->join(', ');
                $message .= '. Floating PCs: ' . $floatingList;
            }

            return redirect()->back()->with('flash', [
                'message' => $message,
                'type' => 'success',
                'floating_pcs' => $floatingPcs,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()->with('flash', [
                'message' => 'Bulk transfer failed: ' . $e->getMessage(),
                'type' => 'error'
            ]);
        }
    }

    /**
     * Remove PC from a station
     */
    public function remove(Request $request)
    {
        $validated = $request->validate([
            'station_id' => 'required|exists:stations,id',
            'notes' => 'nullable|string|max:500',
        ]);

        DB::beginTransaction();
        try {
            $station = Station::findOrFail($validated['station_id']);

            if (!$station->pc_spec_id) {
                return redirect()->back()->with('flash', [
                    'message' => 'Station has no PC assigned',
                    'type' => 'error'
                ]);
            }

            $pcSpecId = $station->pc_spec_id;
            $station->pc_spec_id = null;
            $station->save();

            // Log removal
            PcTransfer::create([
                'from_station_id' => $station->id,
                'to_station_id' => $station->id,
                'pc_spec_id' => $pcSpecId,
                'user_id' => auth()->id(),
                'transfer_type' => 'remove',
                'notes' => $validated['notes'] ?? null,
            ]);

            DB::commit();

            return redirect()->back()->with('flash', [
                'message' => 'PC removed from station successfully',
                'type' => 'success'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()->with('flash', [
                'message' => 'Removal failed: ' . $e->getMessage(),
                'type' => 'error'
            ]);
        }
    }

    /**
     * Get transfer history
     */
    public function history(Request $request)
    {
        $perPage = 20;

        $transfers = PcTransfer::with(['fromStation', 'toStation', 'pcSpec', 'user'])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        $formattedTransfers = $transfers->getCollection()->map(function ($transfer) {
            return [
                'id' => $transfer->id,
                'from_station' => $transfer->fromStation?->station_number,
                'to_station' => $transfer->toStation?->station_number,
                'pc_spec' => $transfer->pcSpec?->model,
                'user' => $transfer->user?->name,
                'transfer_type' => $transfer->transfer_type,
                'notes' => $transfer->notes,
                'created_at' => $transfer->created_at->format('Y-m-d H:i:s'),
            ];
        });

        $paginatorArray = $transfers->toArray();

        return Inertia::render('Station/PcTransfer/History', [
            'transfers' => [
                'data' => $formattedTransfers,
                'links' => $paginatorArray['links'] ?? [],
                'meta' => [
                    'current_page' => $transfers->currentPage(),
                    'last_page' => $transfers->lastPage(),
                    'per_page' => $transfers->perPage(),
                    'total' => $transfers->total(),
                ],
            ],
        ]);
    }

    /**
     * Show dedicated transfer page for bulk transfers
     */
    public function transferPage(Request $request, ?Station $station = null)
    {
        // Get all PC specs with their station assignments
        $pcSpecs = PcSpec::with(['ramSpecs', 'diskSpecs', 'processorSpecs', 'stations'])
            ->get()
            ->map(function ($pc) {
                $assignedStation = $pc->stations->first();
                return [
                    'id' => $pc->id,
                    'pc_number' => $pc->pc_number,
                    'label' => $pc->model,
                    'details' => $pc->getFormattedDetails(),
                    'station' => $assignedStation ? [
                        'id' => $assignedStation->id,
                        'station_number' => $assignedStation->station_number,
                        'site' => $assignedStation->site->name,
                        'site_id' => $assignedStation->site_id,
                        'campaign' => $assignedStation->campaign->name,
                        'campaign_id' => $assignedStation->campaign_id,
                    ] : null,
                ];
            });

        // Get all stations or filter by IDs if provided (bulk mode)
        $stationsQuery = Station::with(['site', 'campaign', 'pcSpec'])
            ->orderBy('station_number', 'asc');

        // If station IDs provided in query string (for bulk mode)
        if ($request->has('stations')) {
            $stationIds = explode(',', $request->input('stations'));
            $stationsQuery->whereIn('id', $stationIds);
        }

        $stations = $stationsQuery->get()->map(function ($st) {
            return [
                'id' => $st->id,
                'station_number' => $st->station_number,
                'site' => $st->site->name,
                'site_id' => $st->site_id,
                'campaign' => $st->campaign->name,
                'campaign_id' => $st->campaign_id,
                'pc_spec_id' => $st->pc_spec_id,
                'pc_spec_details' => $st->pcSpec ? $st->pcSpec->getFormattedDetails() : null,
            ];
        });

        // Get unique sites and campaigns for filters (distinct values only)
        $sites = Site::select('id', 'name')
            ->distinct()
            ->orderBy('name')
            ->get();

        $campaigns = Campaign::select('id', 'name')
            ->distinct()
            ->orderBy('name')
            ->get();

        return Inertia::render('Station/PcTransfer/Transfer', [
            'stations' => $stations,
            'pcSpecs' => $pcSpecs,
            'filters' => [
                'sites' => $sites,
                'campaigns' => $campaigns,
            ],
            'preselectedStationId' => $station?->id,
            'flash' => session('flash') ?? null,
        ]);
    }
}
