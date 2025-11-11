<?php

namespace App\Http\Controllers;

use App\Models\BiometricRetentionPolicy;
use App\Models\Site;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BiometricRetentionPolicyController extends Controller
{
    /**
     * Display retention policies
     */
    public function index()
    {
        $policies = BiometricRetentionPolicy::with('site:id,name')
            ->orderBy('priority', 'desc')
            ->get();

        $sites = Site::select('id', 'name')->orderBy('name')->get();

        return Inertia::render('Attendance/BiometricRecords/RetentionPolicies', [
            'policies' => $policies,
            'sites' => $sites,
        ]);
    }

    /**
     * Store a new retention policy
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'retention_months' => 'required|integer|min:1|max:120',
            'applies_to_type' => 'required|in:global,site',
            'applies_to_id' => 'required_if:applies_to_type,site|nullable|exists:sites,id',
            'priority' => 'nullable|integer|min:0',
        ]);

        $policy = BiometricRetentionPolicy::create([
            'name' => $request->name,
            'description' => $request->description,
            'retention_months' => $request->retention_months,
            'applies_to_type' => $request->applies_to_type,
            'applies_to_id' => $request->applies_to_id,
            'priority' => $request->priority ?? 0,
            'is_active' => true,
        ]);

        return redirect()->back()->with('success', 'Retention policy created successfully');
    }

    /**
     * Update retention policy
     */
    public function update(Request $request, BiometricRetentionPolicy $policy)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'retention_months' => 'required|integer|min:1|max:120',
            'applies_to_type' => 'required|in:global,site',
            'applies_to_id' => 'required_if:applies_to_type,site|nullable|exists:sites,id',
            'priority' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
        ]);

        $policy->update($request->only([
            'name',
            'description',
            'retention_months',
            'applies_to_type',
            'applies_to_id',
            'priority',
            'is_active',
        ]));

        return redirect()->back()->with('success', 'Retention policy updated successfully');
    }

    /**
     * Delete retention policy
     */
    public function destroy(BiometricRetentionPolicy $policy)
    {
        $policy->delete();

        return redirect()->back()->with('success', 'Retention policy deleted successfully');
    }

    /**
     * Toggle policy active status
     */
    public function toggle(BiometricRetentionPolicy $policy)
    {
        $policy->update(['is_active' => !$policy->is_active]);

        return redirect()->back()->with('success', 'Retention policy status updated');
    }
}
