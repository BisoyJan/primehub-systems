<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Lunch Deduction
    |--------------------------------------------------------------------------
    |
    | Automatically deduct lunch break from total_minutes_worked when the
    | employee has worked more than lunch_threshold_hours in a shift.
    |
    */

    'lunch_threshold_hours' => 5,
    'lunch_deduction_minutes' => 60,

    /*
    |--------------------------------------------------------------------------
    | Double-Punch Detection
    |--------------------------------------------------------------------------
    |
    | If a time-in and time-out scan are fewer than this many minutes apart,
    | the pair is flagged as a likely accidental double punch.
    |
    */

    'double_punch_threshold_minutes' => 10,

    /*
    |--------------------------------------------------------------------------
    | Single-Scan Disambiguation
    |--------------------------------------------------------------------------
    |
    | When only one biometric scan exists for a shift, this threshold (hours)
    | determines whether a scan occurring after the scheduled time-out should
    | be treated as a very-late time-in rather than an early time-out.
    |
    */

    'single_scan_post_out_hours' => 2,

    /*
    |--------------------------------------------------------------------------
    | Undertime
    |--------------------------------------------------------------------------
    |
    | Employees who leave early by more than this many minutes are classified
    | as "undertime_more_than_hour" rather than plain "undertime".
    |
    */

    'undertime_threshold_minutes' => 60,

    /*
    |--------------------------------------------------------------------------
    | Maximum Shift Duration
    |--------------------------------------------------------------------------
    |
    | If the duration between time-in and time-out exceeds this many minutes,
    | the time-out is treated as a mismatched scan (e.g. forgot to clock out)
    | and is cleared automatically. Default: 1200 (20 hours).
    |
    */

    'max_shift_duration_minutes' => 1200,

    /*
    |--------------------------------------------------------------------------
    | Undertime Minimum Minutes
    |--------------------------------------------------------------------------
    |
    | Employees must leave at least this many minutes early before the departure
    | is counted as undertime. Values under this threshold are ignored to avoid
    | false positives from second-level rounding.
    |
    */

    'undertime_min_minutes' => 1,

    /*
    |--------------------------------------------------------------------------
    | Overtime Threshold Minutes
    |--------------------------------------------------------------------------
    |
    | Employees must work at least this many minutes beyond their scheduled
    | time-out before overtime_minutes is recorded. Sub-threshold late
    | departures (e.g. wrapping up) are not counted as overtime.
    |
    */

    'overtime_threshold_minutes' => 30,

    /*
    |--------------------------------------------------------------------------
    | Half-Day Absence Tardy Threshold
    |--------------------------------------------------------------------------
    |
    | If an employee arrives more than this many minutes late, the status is
    | automatically escalated from "tardy" to "half_day_absence".
    |
    */

    'half_day_absence_tardy_minutes' => 15,

    /*
    |--------------------------------------------------------------------------
    | Minimum Shift Duration (minutes)
    |--------------------------------------------------------------------------
    |
    | If the time-in → time-out span is above the double-punch threshold but
    | still below this value, a "short_shift" warning is stored on the
    | attendance record for admin review. Does NOT clear the time-out.
    | Default: 60 (1 hour).
    |
    */

    'min_shift_duration_minutes' => 60,

    /*
    |--------------------------------------------------------------------------
    | Queue Upload Size Threshold (bytes)
    |--------------------------------------------------------------------------
    |
    | Attendance uploads whose stored file exceeds this size are dispatched to
    | the job queue instead of being processed synchronously. This prevents
    | HTTP timeouts on large biometric export files.
    | Default: 204800 (200 KB, roughly 2000+ scan rows).
    |
    */

    'queue_upload_size_bytes' => 204800,

];
