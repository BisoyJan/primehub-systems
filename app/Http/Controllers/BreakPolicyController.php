<?php

namespace App\Http\Controllers;

use App\Http\Requests\BreakPolicyRequest;
use App\Http\Traits\RedirectsWithFlashMessages;
use App\Models\BreakPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class BreakPolicyController extends Controller
{
    use RedirectsWithFlashMessages;

    public function index()
    {
        $policies = BreakPolicy::query()
            ->orderBy('is_active', 'desc')
            ->orderBy('name')
            ->get();

        return Inertia::render('BreakTimer/Policies', [
            'policies' => $policies,
        ]);
    }

    public function store(BreakPolicyRequest $request)
    {
        try {
            DB::transaction(function () use ($request) {
                BreakPolicy::create($request->validated());
            });

            return $this->backWithFlash('Break policy created successfully.');
        } catch (\Exception $e) {
            Log::error('BreakPolicy Store Error: '.$e->getMessage());

            return $this->backWithFlash('Failed to create break policy.', 'error');
        }
    }

    public function update(BreakPolicyRequest $request, BreakPolicy $breakPolicy)
    {
        try {
            DB::transaction(function () use ($request, $breakPolicy) {
                $breakPolicy->update($request->validated());
            });

            return $this->backWithFlash('Break policy updated successfully.');
        } catch (\Exception $e) {
            Log::error('BreakPolicy Update Error: '.$e->getMessage());

            return $this->backWithFlash('Failed to update break policy.', 'error');
        }
    }

    public function destroy(BreakPolicy $breakPolicy)
    {
        if ($breakPolicy->breakSessions()->exists()) {
            return $this->backWithFlash(
                'Cannot delete a policy that has associated break sessions.',
                'error'
            );
        }

        try {
            $breakPolicy->delete();

            return $this->backWithFlash('Break policy deleted successfully.');
        } catch (\Exception $e) {
            Log::error('BreakPolicy Destroy Error: '.$e->getMessage());

            return $this->backWithFlash('Failed to delete break policy.', 'error');
        }
    }

    public function toggle(BreakPolicy $breakPolicy)
    {
        try {
            $breakPolicy->update(['is_active' => ! $breakPolicy->is_active]);

            $status = $breakPolicy->is_active ? 'activated' : 'deactivated';

            return $this->backWithFlash("Break policy {$status} successfully.");
        } catch (\Exception $e) {
            Log::error('BreakPolicy Toggle Error: '.$e->getMessage());

            return $this->backWithFlash('Failed to toggle break policy.', 'error');
        }
    }
}
