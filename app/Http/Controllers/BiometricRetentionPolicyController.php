<?php

namespace App\Http\Controllers;

use App\Models\AttendancePoint;
use App\Models\BiometricRecord;
use App\Models\BiometricRetentionPolicy;
use App\Models\Site;
use Carbon\Carbon;
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

        // Get retention stats for each record type
        $retentionStats = $this->getRetentionStats();

        return Inertia::render('Attendance/BiometricRecords/RetentionPolicies', [
            'policies' => $policies,
            'sites' => $sites,
            'retentionStats' => $retentionStats,
        ]);
    }

    /**
     * Get retention statistics for all record types
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
            'biometric_record' => [
                'label' => 'Biometric Records',
                'total' => BiometricRecord::count(),
                'byAge' => [],
            ],
            'attendance_point' => [
                'label' => 'Attendance Points',
                'total' => AttendancePoint::count(),
                'byAge' => [],
            ],
        ];

        foreach ($ageRanges as $range) {
            $startDate = $range['end'] !== null
                ? $now->copy()->subMonths($range['end'])
                : null;
            $endDate = $now->copy()->subMonths($range['start']);

            // Biometric Records use record_date
            $biometricQuery = BiometricRecord::query();
            if ($startDate) {
                $biometricQuery->where('record_date', '>=', $startDate->format('Y-m-d'))
                              ->where('record_date', '<', $endDate->format('Y-m-d'));
            } else {
                $biometricQuery->where('record_date', '<', $endDate->format('Y-m-d'));
            }
            $stats['biometric_record']['byAge'][] = [
                'range' => $range['label'],
                'count' => $biometricQuery->count(),
            ];

            // Attendance Points use shift_date
            $pointsQuery = AttendancePoint::query();
            if ($startDate) {
                $pointsQuery->where('shift_date', '>=', $startDate->format('Y-m-d'))
                           ->where('shift_date', '<', $endDate->format('Y-m-d'));
            } else {
                $pointsQuery->where('shift_date', '<', $endDate->format('Y-m-d'));
            }
            $stats['attendance_point']['byAge'][] = [
                'range' => $range['label'],
                'count' => $pointsQuery->count(),
            ];
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
            'record_type' => 'nullable|in:all,biometric_record,attendance_point',
            'priority' => 'nullable|integer|min:0',
        ]);

        $policy = BiometricRetentionPolicy::create([
            'name' => $request->name,
            'description' => $request->description,
            'retention_months' => $request->retention_months,
            'applies_to_type' => $request->applies_to_type,
            'applies_to_id' => $request->applies_to_id,
            'record_type' => $request->record_type ?? 'all',
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
            'record_type' => 'nullable|in:all,biometric_record,attendance_point',
            'priority' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
        ]);

        $policy->update($request->only([
            'name',
            'description',
            'retention_months',
            'applies_to_type',
            'applies_to_id',
            'record_type',
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

    /**
     * Preview records that would be affected by a policy
     */
    public function preview(BiometricRetentionPolicy $policy)
    {
        $cutoffDate = Carbon::now()->subMonths($policy->retention_months);
        $siteId = $policy->applies_to_id;
        $recordTypes = $policy->record_type === 'all'
            ? ['biometric_record', 'attendance_point']
            : [$policy->record_type];

        $preview = [];

        foreach ($recordTypes as $recordType) {
            if ($recordType === 'biometric_record') {
                $query = BiometricRecord::where('record_date', '<', $cutoffDate->format('Y-m-d'));

                if ($policy->applies_to_type === 'site' && $siteId) {
                    $query->where('site_id', $siteId);
                }

                $count = $query->count();
                $oldestDate = $query->min('record_date');
                $newestDate = $query->max('record_date');

                $preview[] = [
                    'record_type' => 'biometric_record',
                    'label' => 'Biometric Records',
                    'count' => $count,
                    'oldest_date' => $oldestDate,
                    'newest_date' => $newestDate,
                ];
            } elseif ($recordType === 'attendance_point') {
                $query = AttendancePoint::where('shift_date', '<', $cutoffDate->format('Y-m-d'));

                if ($policy->applies_to_type === 'site' && $siteId) {
                    $query->whereHas('user.activeSchedule', function ($q) use ($siteId) {
                        $q->where('site_id', $siteId);
                    });
                }

                $count = $query->count();
                $oldestDate = $query->min('shift_date');
                $newestDate = $query->max('shift_date');

                $preview[] = [
                    'record_type' => 'attendance_point',
                    'label' => 'Attendance Points',
                    'count' => $count,
                    'oldest_date' => $oldestDate,
                    'newest_date' => $newestDate,
                ];
            }
        }

        return response()->json([
            'policy' => $policy->only(['id', 'name', 'retention_months', 'applies_to_type', 'record_type']),
            'cutoff_date' => $cutoffDate->format('Y-m-d'),
            'preview' => $preview,
            'total_affected' => array_sum(array_column($preview, 'count')),
        ]);
    }
}
