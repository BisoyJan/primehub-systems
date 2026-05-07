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

];
