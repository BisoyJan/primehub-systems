<?php

namespace App\Http\Traits;

use App\Models\Stock;
use Illuminate\Support\Facades\Log;

trait HandlesStockOperations
{
    /**
     * Create stock record for a model
     */
    protected function createStock($model, int $quantity): void
    {
        Stock::create([
            'stockable_type' => get_class($model),
            'stockable_id' => $model->id,
            'quantity' => $quantity,
            'reserved' => 0,
        ]);
    }

    /**
     * Check if spec can be deleted (no stock and not in use)
     */
    protected function canDeleteSpec($spec, string $specName): ?array
    {
        // Check if there's stock
        if ($spec->stock && $spec->stock->quantity > 0) {
            return [
                'message' => "Cannot delete {$specName}. It has {$spec->stock->quantity} units in stock. Please remove or transfer the stock first.",
                'type' => 'error'
            ];
        }

        // Check if it's being used in any PC specs
        if (method_exists($spec, 'pcSpecs')) {
            $pcSpecCount = $spec->pcSpecs()->count();
            if ($pcSpecCount > 0) {
                return [
                    'message' => "Cannot delete {$specName}. It is being used in {$pcSpecCount} PC specification(s).",
                    'type' => 'error'
                ];
            }
        }

        // Check if it's being used in any stations (for monitors)
        if (method_exists($spec, 'stations')) {
            $stationCount = $spec->stations()->count();
            if ($stationCount > 0) {
                return [
                    'message' => "Cannot delete {$specName}. It is assigned to {$stationCount} station(s).",
                    'type' => 'error'
                ];
            }
        }

        return null; // Can be deleted
    }

    /**
     * Delete spec with its stock
     */
    protected function deleteSpecWithStock($spec): void
    {
        // Delete the stock record if it exists
        if ($spec->stock) {
            $spec->stock->delete();
        }

        $spec->delete();
    }
}
