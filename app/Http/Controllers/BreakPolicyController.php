<?php

namespace App\Http\Controllers;

use App\Http\Requests\BreakPolicyRequest;
use App\Models\BreakPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class BreakPolicyController extends Controller
{
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

            return redirect()->back()->with('flash', [
                'message' => 'Break policy created successfully.',
                'type' => 'success',
            ]);
        } catch (\Exception $e) {
            Log::error('BreakPolicy Store Error: '.$e->getMessage());

            return redirect()->back()->with('flash', [
                'message' => 'Failed to create break policy.',
                'type' => 'error',
            ]);
        }
    }

    public function update(BreakPolicyRequest $request, BreakPolicy $breakPolicy)
    {
        try {
            DB::transaction(function () use ($request, $breakPolicy) {
                $breakPolicy->update($request->validated());
            });

            return redirect()->back()->with('flash', [
                'message' => 'Break policy updated successfully.',
                'type' => 'success',
            ]);
        } catch (\Exception $e) {
            Log::error('BreakPolicy Update Error: '.$e->getMessage());

            return redirect()->back()->with('flash', [
                'message' => 'Failed to update break policy.',
                'type' => 'error',
            ]);
        }
    }

    public function destroy(BreakPolicy $breakPolicy)
    {
        if ($breakPolicy->breakSessions()->exists()) {
            return redirect()->back()->with('flash', [
                'message' => 'Cannot delete a policy that has associated break sessions.',
                'type' => 'error',
            ]);
        }

        try {
            $breakPolicy->delete();

            return redirect()->back()->with('flash', [
                'message' => 'Break policy deleted successfully.',
                'type' => 'success',
            ]);
        } catch (\Exception $e) {
            Log::error('BreakPolicy Destroy Error: '.$e->getMessage());

            return redirect()->back()->with('flash', [
                'message' => 'Failed to delete break policy.',
                'type' => 'error',
            ]);
        }
    }

    public function toggle(BreakPolicy $breakPolicy)
    {
        try {
            $breakPolicy->update(['is_active' => ! $breakPolicy->is_active]);

            $status = $breakPolicy->is_active ? 'activated' : 'deactivated';

            return redirect()->back()->with('flash', [
                'message' => "Break policy {$status} successfully.",
                'type' => 'success',
            ]);
        } catch (\Exception $e) {
            Log::error('BreakPolicy Toggle Error: '.$e->getMessage());

            return redirect()->back()->with('flash', [
                'message' => 'Failed to toggle break policy.',
                'type' => 'error',
            ]);
        }
    }
}
