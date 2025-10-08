<?php
// app/Http/Controllers/StockController.php

namespace App\Http\Controllers;

use App\Models\Stock;
use App\Models\RamSpec;
use App\Models\DiskSpec;
use App\Models\ProcessorSpec;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class StockController extends Controller
{
    /**
     * Map short type keys to model class names.
     */
    protected array $typeMap = [
        'ram' => RamSpec::class,
        'disk' => DiskSpec::class,
        'processor' => ProcessorSpec::class,
    ];

    /**
     * GET /stocks
     *
     * Inertia index page. Accepts optional query params:
     *  - type=ram|disk|processor
     *  - ids[]=1&ids[]=2
     *
     * Returns Inertia page with stocks and convenience metadata for the UI.
     */
    // Replace the existing index method in app/Http/Controllers/StockController.php with this paginated version.

    public function index(Request $request)
    {
        $type = $request->query('type');
        $ids = array_filter(array_map('intval', (array) $request->query('ids', [])));
        $perPage = (int) $request->query('per_page', 15);

        $query = Stock::query()->with('stockable');

        if ($type) {
            if (! isset($this->typeMap[$type])) {
                return back()->withErrors(['type' => 'Invalid type']);
            }
            $query->where('stockable_type', $this->typeMap[$type]);
        }

        if (! empty($ids)) {
            $query->whereIn('stockable_id', $ids);
        }

        $paginated = $query->orderBy('id', 'desc')->paginate($perPage)->withQueryString();

        // normalize items for the UI
        $items = $paginated->getCollection()->map(function (Stock $s) {
            $typeKey = $this->mapTypeKey($s->stockable_type);
            $label = null;
            if ($s->relationLoaded('stockable') && $s->stockable) {
                if (isset($s->stockable->label)) $label = $s->stockable->label;
                elseif (isset($s->stockable->name)) $label = $s->stockable->name;
                elseif (isset($s->stockable->model)) $label = $s->stockable->model;
                else $label = class_basename($s->stockable_type) . ' #' . $s->stockable_id;
            } else {
                $label = class_basename($s->stockable_type) . ' #' . $s->stockable_id;
            }

            return [
                'id' => $s->id,
                'stockable_type' => $s->stockable_type,
                'stockable_id' => $s->stockable_id,
                'type' => $typeKey,
                'quantity' => (int) $s->quantity,
                'reserved' => (int) $s->reserved,
                'location' => $s->location,
                'notes' => $s->notes,
                'stockable' => ['id' => $s->stockable_id, 'label' => $label],
                'created_at' => $s->created_at ? $s->created_at->toDateTimeString() : null,
                'updated_at' => $s->updated_at ? $s->updated_at->toDateTimeString() : null,
            ];
        })->toArray();

        // Use paginator->toArray() to extract the links structure Laravel provides
        $paginatorArray = $paginated->toArray();
        $links = $paginatorArray['links'] ?? [];

        return Inertia::render('Stocks/Index', [
            // Shadcn-style pagination helpers expect { data: [...], links: [...] }
            'stocks' => [
                'data' => $items,
                'links' => $links,
                'meta' => [
                    'current_page' => $paginated->currentPage(),
                    'last_page' => $paginated->lastPage(),
                    'per_page' => $paginated->perPage(),
                    'total' => $paginated->total(),
                ],
            ],
            'filterType' => $type ?? 'all',
            'flash' => session('flash') ?? null,
        ]);
    }

    /**
     * POST /stocks
     *
     * Create or upsert a stock row for a spec; returns Inertia redirect.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'type' => 'required|string|in:ram,disk,processor',
            'stockable_id' => 'required|integer|min:1',
            'quantity' => 'nullable|integer|min:0',
            'reserved' => 'nullable|integer|min:0',
            'location' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        $modelClass = $this->typeMap[$data['type']];

        $target = $modelClass::find($data['stockable_id']);
        if (! $target) {
            throw ValidationException::withMessages(['stockable_id' => 'Referenced spec not found']);
        }

        $stock = Stock::updateOrCreate(
            ['stockable_type' => $modelClass, 'stockable_id' => $data['stockable_id']],
            [
                'quantity' => $data['quantity'] ?? 0,
                'reserved' => $data['reserved'] ?? 0,
                'location' => $data['location'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]
        );

        return redirect()->back()->with('flash', ['message' => 'Stock saved', 'type' => 'success']);
    }

    /**
     * GET /stocks/{id}
     *
     * Show a single stock row as JSON (useful for quick fetchs in UI). For Inertia flows you may not need this.
     */
    public function show(Stock $stock)
    {
        $stock->load('stockable');
        return response()->json($stock);
    }

    /**
     * PUT/PATCH /stocks/{id}
     *
     * Update stock row. Accepts full replacement or delta_x fields.
     * Returns an Inertia redirect back with flash.
     */
    public function update(Request $request, Stock $stock)
    {
        $data = $request->validate([
            'quantity' => 'nullable|integer|min:0',
            'reserved' => 'nullable|integer|min:0',
            'location' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            // optional delta operations
            'delta_quantity' => 'nullable|integer',
            'delta_reserved' => 'nullable|integer',
        ]);

        DB::transaction(function () use ($stock, $data) {
            $s = Stock::where('id', $stock->id)->lockForUpdate()->first();

            if (isset($data['delta_quantity'])) {
                $new = max(0, ($s->quantity ?? 0) + (int)$data['delta_quantity']);
                $s->quantity = $new;
            } elseif (isset($data['quantity'])) {
                $s->quantity = (int)$data['quantity'];
            }

            if (isset($data['delta_reserved'])) {
                $newReserved = max(0, ($s->reserved ?? 0) + (int)$data['delta_reserved']);
                $s->reserved = $newReserved;
            } elseif (isset($data['reserved'])) {
                $s->reserved = (int)$data['reserved'];
            }

            if (array_key_exists('location', $data)) {
                $s->location = $data['location'];
            }
            if (array_key_exists('notes', $data)) {
                $s->notes = $data['notes'];
            }

            $s->save();
        });

        return redirect()->back()->with('flash', ['message' => 'Stock updated', 'type' => 'success']);
    }

    /**
     * DELETE /stocks/{id}
     */
    public function destroy(Stock $stock)
    {
        $stock->delete();
        return redirect()->back()->with('flash', ['message' => 'Stock deleted', 'type' => 'success']);
    }

    /**
     * POST /stocks/adjust
     *
     * Convenience atomic adjust by type + stockable_id. Returns back with flash.
     */
    public function adjust(Request $request)
    {
        $data = $request->validate([
            'type' => 'required|string|in:ram,disk,processor',
            'stockable_id' => 'required|integer|min:1',
            'delta' => 'required|integer',
        ]);

        $modelClass = $this->typeMap[$data['type']];
        $spec = $modelClass::find($data['stockable_id']);
        if (! $spec) {
            throw ValidationException::withMessages(['stockable_id' => 'Referenced spec not found']);
        }

        $stock = Stock::firstOrCreate(
            ['stockable_type' => $modelClass, 'stockable_id' => $data['stockable_id']],
            ['quantity' => 0, 'reserved' => 0]
        );

        DB::transaction(function () use ($stock, $data) {
            $s = Stock::where('id', $stock->id)->lockForUpdate()->first();
            $new = max(0, ($s->quantity ?? 0) + (int)$data['delta']);
            $s->quantity = $new;
            $s->save();
        });

        return redirect()->back()->with('flash', ['message' => 'Stock adjusted', 'type' => 'success']);
    }

    /**
     * Helper to convert full model class to short key used in UI.
     */
    protected function mapTypeKey(string $stockableType): string
    {
        $base = class_basename($stockableType);
        if (stripos($base, 'Ram') !== false) return 'ram';
        if (stripos($base, 'Disk') !== false) return 'disk';
        if (stripos($base, 'Processor') !== false) return 'processor';
        return 'ram';
    }
}
