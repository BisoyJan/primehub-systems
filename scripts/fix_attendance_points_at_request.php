<?php

/**
 * Fix Attendance Points at Request Data Inconsistency
 *
 * This script recalculates the attendance_points_at_request field for all leave requests
 * based on the points that were ACTIVE at the time the leave request was submitted.
 *
 * A point is considered "active at request time" if:
 * 1. The shift_date (violation date) was before the request was submitted
 * 2. AND either:
 *    - Still active today (not excused, not expired)
 *    - OR was excused AFTER the request was submitted
 *    - OR was expired AFTER the request was submitted
 *
 * Usage:
 * 1. DRY RUN (see what would change):
 *    php artisan tinker scripts/fix_attendance_points_at_request.php
 *
 * 2. ACTUAL UPDATE (apply changes):
 *    First create the flag file, then run the script:
 *    echo. > scripts/.apply_flag && php artisan tinker scripts/fix_attendance_points_at_request.php
 */

use App\Models\LeaveRequest;
use App\Models\AttendancePoint;

// Check if APPLY mode - look for a flag file in the same directory
$applyFlagFile = __DIR__ . '/.apply_flag';
$dryRun = !file_exists($applyFlagFile);

// Clean up the flag file if it exists (single use)
if (file_exists($applyFlagFile)) {
    unlink($applyFlagFile);
}

echo "=============================================================\n";
echo "  FIX ATTENDANCE POINTS AT REQUEST DATA INCONSISTENCY\n";
echo "=============================================================\n\n";

if ($dryRun) {
    echo "🔍 DRY RUN MODE - No changes will be made\n";
    echo "   Run with --apply flag to actually update records\n\n";
} else {
    echo "⚠️  APPLY MODE - Records WILL be updated!\n\n";
}

$leaveRequests = LeaveRequest::whereNotNull('attendance_points_at_request')
    ->with('user:id,first_name,last_name')
    ->orderBy('id')
    ->get();

echo "Found " . $leaveRequests->count() . " leave requests with attendance_points_at_request set.\n\n";

$changes = [];
$noChanges = [];

foreach ($leaveRequests as $leaveRequest) {
    $requestSubmittedAt = $leaveRequest->created_at;

    // Calculate points that were active at the time of submission
    $activePointsAtRequest = AttendancePoint::where('user_id', $leaveRequest->user_id)
        ->where('shift_date', '<', $requestSubmittedAt)
        ->where(function ($query) use ($requestSubmittedAt) {
            $query->where(function ($q) {
                // Currently active (not excused, not expired)
                $q->where('is_excused', false)->where('is_expired', false);
            })
            ->orWhere(function ($q) use ($requestSubmittedAt) {
                // Was excused AFTER the request was submitted
                $q->where('is_excused', true)
                  ->whereNotNull('excused_at')
                  ->where('excused_at', '>', $requestSubmittedAt);
            })
            ->orWhere(function ($q) use ($requestSubmittedAt) {
                // Was expired AFTER the request was submitted
                $q->where('is_expired', true)
                  ->whereNotNull('expired_at')
                  ->where('expired_at', '>', $requestSubmittedAt);
            });
        })
        ->get();

    $calculatedPoints = $activePointsAtRequest->sum('points');
    $storedPoints = (float) $leaveRequest->attendance_points_at_request;

    // Check if there's a difference (with small tolerance for floating point)
    if (abs($storedPoints - $calculatedPoints) > 0.001) {
        $changes[] = [
            'id' => $leaveRequest->id,
            'user' => $leaveRequest->user ? ($leaveRequest->user->last_name . ', ' . $leaveRequest->user->first_name) : 'Unknown',
            'submitted_at' => $requestSubmittedAt->format('Y-m-d H:i'),
            'stored' => $storedPoints,
            'calculated' => $calculatedPoints,
            'difference' => $calculatedPoints - $storedPoints,
            'points_detail' => $activePointsAtRequest->map(function ($p) use ($requestSubmittedAt) {
                $status = 'active';
                if ($p->is_excused && $p->excused_at > $requestSubmittedAt) {
                    $status = 'excused after (' . $p->excused_at->format('Y-m-d') . ')';
                } elseif ($p->is_expired && $p->expired_at > $requestSubmittedAt) {
                    $status = 'expired after (' . $p->expired_at->format('Y-m-d') . ')';
                }
                return [
                    'shift_date' => $p->shift_date->format('Y-m-d'),
                    'type' => $p->point_type,
                    'points' => $p->points,
                    'status' => $status,
                ];
            })->toArray(),
        ];
    } else {
        $noChanges[] = $leaveRequest->id;
    }
}

// Display results
echo "-------------------------------------------------------------\n";
echo "SUMMARY\n";
echo "-------------------------------------------------------------\n";
echo "Records with changes needed: " . count($changes) . "\n";
echo "Records already correct:     " . count($noChanges) . "\n\n";

if (count($changes) > 0) {
    echo "-------------------------------------------------------------\n";
    echo "CHANGES TO BE MADE\n";
    echo "-------------------------------------------------------------\n\n";

    foreach ($changes as $change) {
        echo "Leave Request #{$change['id']}\n";
        echo "  User:       {$change['user']}\n";
        echo "  Submitted:  {$change['submitted_at']}\n";
        echo "  Stored:     {$change['stored']} points\n";
        echo "  Calculated: {$change['calculated']} points\n";
        echo "  Difference: " . ($change['difference'] > 0 ? '+' : '') . "{$change['difference']} points\n";

        if (!empty($change['points_detail'])) {
            echo "  Points breakdown:\n";
            foreach ($change['points_detail'] as $detail) {
                echo "    - {$detail['shift_date']} | {$detail['type']} | {$detail['points']} pts | {$detail['status']}\n";
            }
        } else {
            echo "  Points breakdown: No points found (will set to 0)\n";
        }
        echo "\n";
    }

    // Apply changes if not dry run
    if (!$dryRun) {
        echo "-------------------------------------------------------------\n";
        echo "APPLYING CHANGES...\n";
        echo "-------------------------------------------------------------\n\n";

        $updated = 0;
        foreach ($changes as $change) {
            LeaveRequest::where('id', $change['id'])
                ->update(['attendance_points_at_request' => $change['calculated']]);
            echo "✅ Updated Leave Request #{$change['id']}: {$change['stored']} -> {$change['calculated']}\n";
            $updated++;
        }

        echo "\n✅ Successfully updated {$updated} leave requests.\n";
    } else {
        echo "-------------------------------------------------------------\n";
        echo "To apply these changes, run:\n";
        echo "  echo. > scripts/.apply_flag && php artisan tinker scripts/fix_attendance_points_at_request.php\n";
        echo "-------------------------------------------------------------\n";
    }
} else {
    echo "✅ All records are already consistent. No changes needed.\n";
}

echo "\nDone.\n";
