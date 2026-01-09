<?php

namespace App\Http\Controllers;

use App\Models\FormRequestRetentionPolicy;
use App\Models\ItConcern;
use App\Models\LeaveCredit;
use App\Models\LeaveRequest;
use App\Models\MedicationRequest;
use App\Models\Site;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class FormRequestRetentionPolicyController extends Controller
{
    /**
     * Display retention policies
     */
    public function index()
    {
        $policies = FormRequestRetentionPolicy::with('site:id,name')
            ->orderBy('priority', 'desc')
            ->get();

        $sites = Site::select('id', 'name')->orderBy('name')->get();

        // Get retention stats for each form type
        $retentionStats = $this->getRetentionStats();

        return Inertia::render('FormRequest/RetentionPolicies', [
            'policies' => $policies,
            'sites' => $sites,
            'retentionStats' => $retentionStats,
        ]);
    }

    /**
     * Get retention statistics for all form types
     */
    protected function getRetentionStats(): array
    {
        $now = Carbon::now();
        $ageRanges = [
            ['label' => '0-3 months', 'start' => 0, 'end' => 3],
            ['label' => '3-6 months', 'start' => 3, 'end' => 6],
            ['label' => '6-12 months', 'start' => 6, 'end' => 12],
            ['label' => '12-24 months', 'start' => 12, 'end' => 24],
            ['label' => '24+ months', 'start' => 24, 'end' => null],
        ];

        $stats = [
            'leave_request' => [
                'label' => 'Leave Requests',
                'total' => LeaveRequest::count(),
                'byAge' => [],
            ],
            'it_concern' => [
                'label' => 'IT Concerns',
                'total' => ItConcern::count(),
                'byAge' => [],
            ],
            'medication_request' => [
                'label' => 'Medication Requests',
                'total' => MedicationRequest::count(),
                'byAge' => [],
            ],
            'leave_credit' => [
                'label' => 'Leave Credits',
                'total' => LeaveCredit::count(),
                'byAge' => [],
            ],
        ];

        foreach ($ageRanges as $range) {
            $startDate = $range['end'] !== null
                ? $now->copy()->subMonths($range['end'])
                : null;
            $endDate = $now->copy()->subMonths($range['start']);

            foreach ($stats as $type => &$data) {
                $query = match ($type) {
                    'leave_request' => LeaveRequest::query(),
                    'it_concern' => ItConcern::query(),
                    'medication_request' => MedicationRequest::query(),
                    'leave_credit' => LeaveCredit::query(),
                };

                if ($startDate) {
                    $query->where('created_at', '>=', $startDate)
                          ->where('created_at', '<', $endDate);
                } else {
                    $query->where('created_at', '<', $endDate);
                }

                $data['byAge'][] = [
                    'range' => $range['label'],
                    'count' => $query->count(),
                ];
            }
        }

        return $stats;
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
            'form_type' => 'nullable|in:all,leave_request,it_concern,medication_request,leave_credit',
            'priority' => 'nullable|integer|min:0',
        ]);

        $policy = FormRequestRetentionPolicy::create([
            'name' => $request->name,
            'description' => $request->description,
            'retention_months' => $request->retention_months,
            'applies_to_type' => $request->applies_to_type,
            'applies_to_id' => $request->applies_to_id,
            'form_type' => $request->form_type ?? 'all',
            'priority' => $request->priority ?? 0,
            'is_active' => true,
        ]);

        return redirect()->back()->with('success', 'Retention policy created successfully');
    }

    /**
     * Update retention policy
     */
    public function update(Request $request, FormRequestRetentionPolicy $policy)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'retention_months' => 'required|integer|min:1|max:120',
            'applies_to_type' => 'required|in:global,site',
            'applies_to_id' => 'required_if:applies_to_type,site|nullable|exists:sites,id',
            'form_type' => 'nullable|in:all,leave_request,it_concern,medication_request,leave_credit',
            'priority' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
        ]);

        $policy->update($request->only([
            'name',
            'description',
            'retention_months',
            'applies_to_type',
            'applies_to_id',
            'form_type',
            'priority',
            'is_active',
        ]));

        return redirect()->back()->with('success', 'Retention policy updated successfully');
    }

    /**
     * Delete retention policy
     */
    public function destroy(FormRequestRetentionPolicy $policy)
    {
        $policy->delete();

        return redirect()->back()->with('success', 'Retention policy deleted successfully');
    }

    /**
     * Toggle policy active status
     */
    public function toggle(FormRequestRetentionPolicy $policy)
    {
        $policy->update(['is_active' => !$policy->is_active]);

        return redirect()->back()->with('success', 'Retention policy status updated');
    }

    /**
     * Preview records that would be affected by a policy
     */
    public function preview(FormRequestRetentionPolicy $policy)
    {
        $cutoffDate = Carbon::now()->subMonths($policy->retention_months);
        $siteId = $policy->applies_to_id;
        $formTypes = $policy->form_type === 'all'
            ? ['leave_request', 'it_concern', 'medication_request', 'leave_credit']
            : [$policy->form_type];

        $preview = [];

        foreach ($formTypes as $formType) {
            $query = match ($formType) {
                'leave_request' => LeaveRequest::query(),
                'it_concern' => ItConcern::query(),
                'medication_request' => MedicationRequest::query(),
                'leave_credit' => LeaveCredit::query(),
                default => null,
            };

            if (!$query) {
                continue;
            }

            $query->where('created_at', '<', $cutoffDate);

            // Apply site filter
            if ($policy->applies_to_type === 'site' && $siteId) {
                if ($formType === 'it_concern') {
                    $query->where('site_id', $siteId);
                } else {
                    $query->whereHas('user.activeSchedule', function ($q) use ($siteId) {
                        $q->where('site_id', $siteId);
                    });
                }
            } elseif ($policy->applies_to_type === 'global') {
                // For global policies, count all records regardless of site
            }

            $count = $query->count();
            $oldestDate = $query->min('created_at');
            $newestDate = $query->max('created_at');

            $preview[] = [
                'form_type' => $formType,
                'label' => match ($formType) {
                    'leave_request' => 'Leave Requests',
                    'it_concern' => 'IT Concerns',
                    'medication_request' => 'Medication Requests',
                    'leave_credit' => 'Leave Credits',
                    default => ucfirst(str_replace('_', ' ', $formType)),
                },
                'count' => $count,
                'oldest_date' => $oldestDate,
                'newest_date' => $newestDate,
            ];
        }

        return response()->json([
            'policy' => $policy->only(['id', 'name', 'retention_months', 'applies_to_type', 'form_type']),
            'cutoff_date' => $cutoffDate->format('Y-m-d'),
            'preview' => $preview,
            'total_affected' => array_sum(array_column($preview, 'count')),
        ]);
    }
}
