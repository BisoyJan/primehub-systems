<?php

namespace App\Http\Controllers;

use App\Http\Requests\BreakRetentionPolicyRequest;
use App\Http\Traits\RedirectsWithFlashMessages;
use App\Models\BreakRetentionPolicy;
use App\Services\BreakRetentionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BreakRetentionPolicyController extends Controller
{
    use RedirectsWithFlashMessages;

    public function store(BreakRetentionPolicyRequest $request): RedirectResponse
    {
        try {
            DB::transaction(function () use ($request) {
                BreakRetentionPolicy::create($request->policyAttributes());
            });

            return $this->backWithFlash('Retention policy created successfully.');
        } catch (\Exception $e) {
            Log::error('BreakRetentionPolicy Store Error: '.$e->getMessage());

            return $this->backWithFlash('Failed to create retention policy.', 'error');
        }
    }

    public function update(BreakRetentionPolicyRequest $request, BreakRetentionPolicy $breakRetentionPolicy): RedirectResponse
    {
        try {
            DB::transaction(function () use ($request, $breakRetentionPolicy) {
                $breakRetentionPolicy->update($request->policyAttributes());
            });

            return $this->backWithFlash('Retention policy updated successfully.');
        } catch (\Exception $e) {
            Log::error('BreakRetentionPolicy Update Error: '.$e->getMessage());

            return $this->backWithFlash('Failed to update retention policy.', 'error');
        }
    }

    public function destroy(BreakRetentionPolicy $breakRetentionPolicy): RedirectResponse
    {
        try {
            $breakRetentionPolicy->delete();

            return $this->backWithFlash('Retention policy deleted successfully.');
        } catch (\Exception $e) {
            Log::error('BreakRetentionPolicy Destroy Error: '.$e->getMessage());

            return $this->backWithFlash('Failed to delete retention policy.', 'error');
        }
    }

    public function toggle(BreakRetentionPolicy $breakRetentionPolicy): RedirectResponse
    {
        try {
            $breakRetentionPolicy->update(['is_active' => ! $breakRetentionPolicy->is_active]);

            $status = $breakRetentionPolicy->is_active ? 'activated' : 'deactivated';

            return $this->backWithFlash("Retention policy {$status} successfully.");
        } catch (\Exception $e) {
            Log::error('BreakRetentionPolicy Toggle Error: '.$e->getMessage());

            return $this->backWithFlash('Failed to toggle retention policy.', 'error');
        }
    }

    public function preview(BreakRetentionPolicy $breakRetentionPolicy, BreakRetentionService $retention): JsonResponse
    {
        return response()->json($retention->preview($breakRetentionPolicy));
    }
}
