<?php

namespace App\Http\Controllers;

use App\Http\Requests\PcBulkTransferRequest;
use App\Http\Requests\PcRemoveRequest;
use App\Http\Requests\PcTransferRequest;
use App\Http\Traits\RedirectsWithFlashMessages;
use App\Models\Campaign;
use App\Models\PcSpec;
use App\Models\PcTransfer;
use App\Models\Site;
use App\Models\Station;
use App\Services\PcTransferService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PcTransferController extends Controller
{
    use RedirectsWithFlashMessages;

    public function __construct(protected PcTransferService $transferService) {}

    /**
     * Display the PC transfer interface
     */
    public function index(Request $request): Response
    {
        $search = $request->query('search');
        $siteFilter = $request->query('site');
        $campaignFilter = $request->query('campaign');
        $perPage = 50;

        // Get stations with their PC specs, ordered by site then station number
        $query = Station::select('stations.*')
            ->with(['site', 'campaign', 'pcSpec.processorSpecs'])
            ->join('sites', 'stations.site_id', '=', 'sites.id')
            ->orderBy('sites.name')
            ->orderByRaw('CAST(stations.station_number AS UNSIGNED), stations.station_number');

        // Apply filters
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('station_number', 'like', "%{$search}%")
                    ->orWhereHas('site', fn ($sq) => $sq->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('campaign', fn ($cq) => $cq->where('name', 'like', "%{$search}%"));
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
            'pcSpecs' => $this->pcSpecOptions(),
            'stationOptions' => $this->stationOptions(),
            'filters' => [
                'sites' => Site::query()->orderBy('name')->get(['id', 'name']),
                'campaigns' => Campaign::query()->orderBy('name')->get(['id', 'name']),
            ],
        ]);
    }

    /**
     * Transfer a single PC to a station (assign, replace or swap).
     */
    public function transfer(PcTransferRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        try {
            $result = $this->transferService->bulkTransfer(
                [[
                    'to_station_id' => $validated['to_station_id'],
                    'pc_spec_id' => $validated['pc_spec_id'],
                    'transfer_type' => $validated['transfer_type'],
                ]],
                $validated['notes'] ?? null,
                (int) $request->user()->id,
            );
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('PcTransferController transfer error: '.$e->getMessage());

            return $this->backWithFlash('Transfer failed. Please try again.', 'error');
        }

        return $this->backWithFlash($this->transferService->describeResult($result));
    }

    /**
     * Bulk transfer multiple PCs
     */
    public function bulkTransfer(PcBulkTransferRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        try {
            $result = $this->transferService->bulkTransfer(
                $validated['transfers'],
                $validated['notes'] ?? null,
                (int) $request->user()->id,
            );
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('PcTransferController bulkTransfer error: '.$e->getMessage());

            return $this->backWithFlash('Bulk transfer failed. Please try again.', 'error');
        }

        return $this->redirectWithFlash('pc-transfers.index', $this->transferService->describeResult($result));
    }

    /**
     * Remove PC from a station
     */
    public function remove(PcRemoveRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $station = Station::query()->findOrFail($validated['station_id']);

        try {
            $this->transferService->remove($station, $validated['notes'] ?? null, (int) $request->user()->id);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('PcTransferController remove error: '.$e->getMessage());

            return $this->backWithFlash('Removal failed. Please try again.', 'error');
        }

        return $this->backWithFlash('PC removed from station successfully');
    }

    /**
     * Get transfer history
     */
    public function history(Request $request): Response
    {
        $perPage = 20;
        $search = trim((string) $request->query('search', ''));
        $type = $request->query('type');
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');

        $query = PcTransfer::query()
            ->with(['fromStation', 'toStation', 'pcSpec', 'user'])
            ->orderBy('created_at', 'desc');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->whereHas('pcSpec', fn ($pq) => $pq->where('pc_number', 'like', "%{$search}%"))
                    ->orWhereHas('fromStation', fn ($sq) => $sq->where('station_number', 'like', "%{$search}%"))
                    ->orWhereHas('toStation', fn ($sq) => $sq->where('station_number', 'like', "%{$search}%"))
                    ->orWhereHas('user', fn ($uq) => $uq->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%"));
            });
        }

        if ($type && $type !== 'all') {
            $query->where('transfer_type', $type);
        }

        if ($dateFrom) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }

        if ($dateTo) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        $transfers = $query->paginate($perPage)->withQueryString();

        $formattedTransfers = $transfers->getCollection()->map(function ($transfer) {
            return [
                'id' => $transfer->id,
                'from_station' => $transfer->fromStation?->station_number,
                'to_station' => $transfer->toStation?->station_number,
                'pc_spec' => $transfer->pcSpec ? ($transfer->pcSpec->pc_number ?? $transfer->pcSpec->manufacturer) : null,
                'user' => $transfer->user?->name,
                'transfer_type' => $transfer->transfer_type,
                'notes' => $transfer->notes,
                'created_at' => $transfer->created_at->toIso8601String(),
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
            'filters' => [
                'search' => $search,
                'type' => $type ?: 'all',
                'date_from' => $dateFrom ?: '',
                'date_to' => $dateTo ?: '',
            ],
        ]);
    }

    /**
     * Show dedicated transfer page for bulk transfers
     */
    public function transferPage(Request $request, ?Station $station = null): Response
    {
        $stationsQuery = Station::select('stations.*')
            ->with(['site', 'campaign', 'pcSpec.processorSpecs'])
            ->join('sites', 'stations.site_id', '=', 'sites.id')
            ->orderBy('sites.name')
            ->orderByRaw('CAST(stations.station_number AS UNSIGNED), stations.station_number');

        if ($request->filled('stations')) {
            $stationIds = collect(explode(',', (string) $request->input('stations')))
                ->map(fn ($id) => (int) trim($id))
                ->filter()
                ->all();
            $stationsQuery->whereIn('stations.id', $stationIds);
        }

        $stations = $stationsQuery->get()->map(function ($st) {
            return [
                'id' => $st->id,
                'station_number' => $st->station_number,
                'site' => $st->site?->name,
                'site_id' => $st->site_id,
                'campaign' => $st->campaign?->name,
                'campaign_id' => $st->campaign_id,
                'pc_spec_id' => $st->pc_spec_id,
                'pc_spec_details' => $st->pcSpec ? $st->pcSpec->getFormattedDetails() : null,
            ];
        });

        $siteFilter = $request->query('site');
        $campaignFilter = $request->query('campaign');

        return Inertia::render('Station/PcTransfer/Transfer', [
            'stations' => $stations,
            'pcSpecs' => $this->pcSpecOptions(),
            'filters' => [
                'sites' => Site::query()->orderBy('name')->get(['id', 'name']),
                'campaigns' => Campaign::query()->orderBy('name')->get(['id', 'name']),
            ],
            'initialFilters' => [
                'site' => $siteFilter && $siteFilter !== 'all' ? (string) $siteFilter : ($station?->site_id ? (string) $station->site_id : 'all'),
                'campaign' => $campaignFilter && $campaignFilter !== 'all' ? (string) $campaignFilter : 'all',
                'search' => (string) $request->query('search', ''),
            ],
            'preselectedStationId' => $station?->id,
        ]);
    }

    /**
     * All PC specs with their current station, formatted for pickers.
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected function pcSpecOptions()
    {
        return PcSpec::query()
            ->with(['processorSpecs', 'stations.site', 'stations.campaign'])
            ->orderBy('pc_number')
            ->get()
            ->map(function (PcSpec $pc) {
                $assignedStation = $pc->stations->first();

                return [
                    'id' => $pc->id,
                    'pc_number' => $pc->pc_number,
                    'label' => $pc->pc_number ?? $pc->manufacturer,
                    'details' => $pc->getFormattedDetails(),
                    'station' => $assignedStation ? [
                        'id' => $assignedStation->id,
                        'station_number' => $assignedStation->station_number,
                        'site' => $assignedStation->site?->name,
                        'site_id' => $assignedStation->site_id,
                        'campaign' => $assignedStation->campaign?->name,
                        'campaign_id' => $assignedStation->campaign_id,
                    ] : null,
                ];
            });
    }

    /**
     * Lightweight list of every station for destination pickers.
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected function stationOptions()
    {
        return Station::select('stations.*')
            ->with(['site', 'pcSpec:id,pc_number'])
            ->join('sites', 'stations.site_id', '=', 'sites.id')
            ->orderBy('sites.name')
            ->orderByRaw('CAST(stations.station_number AS UNSIGNED), stations.station_number')
            ->get()
            ->map(fn (Station $st) => [
                'id' => $st->id,
                'station_number' => $st->station_number,
                'site' => $st->site?->name,
                'site_id' => $st->site_id,
                'campaign_id' => $st->campaign_id,
                'pc_spec_id' => $st->pc_spec_id,
                'pc_number' => $st->pcSpec?->pc_number,
            ]);
    }
}
