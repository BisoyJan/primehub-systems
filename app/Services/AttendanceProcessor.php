<?php

namespace App\Services;

use App\Models\User;
use App\Models\Attendance;
use App\Models\EmployeeSchedule;
use App\Models\AttendanceUpload;
use App\Models\BiometricRecord;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AttendanceProcessor
{
    protected AttendanceFileParser $parser;

    public function __construct(AttendanceFileParser $parser)
    {
        $this->parser = $parser;
    }

    /**
     * Process uploaded attendance file.
     *
     * @param AttendanceUpload $upload
     * @param string $filePath
     * @return array Processing results
     */
    public function processUpload(AttendanceUpload $upload, string $filePath): array
    {
        try {
            $upload->update(['status' => 'processing']);

            // Parse the file
            $records = $this->parser->parse($filePath);
            $groupedRecords = $this->parser->groupByEmployee($records);

            \Log::info('Attendance processing started', [
                'total_records' => $records->count(),
                'unique_employees' => $groupedRecords->count(),
            ]);

            // Save all raw biometric records to database
            $this->saveBiometricRecords($records, $upload);

            // Validate file dates match expected dates for the shift
            $dateValidation = $this->validateFileDates($records, Carbon::parse($upload->shift_date));

            $stats = [
                'total_records' => $records->count(),
                'processed' => 0,
                'matched_employees' => 0,
                'unmatched_names' => [],
                'errors' => [],
                'date_warnings' => $dateValidation['warnings'],
                'dates_found' => $dateValidation['dates_found'],
            ];

            // Process each employee's records
            foreach ($groupedRecords as $normalizedName => $employeeRecords) {
                \Log::debug('Processing employee', [
                    'normalized_name' => $normalizedName,
                    'original_name' => $employeeRecords->first()['name'],
                    'record_count' => $employeeRecords->count(),
                ]);

                $result = $this->processEmployeeRecords(
                    $normalizedName,
                    $employeeRecords,
                    Carbon::parse($upload->shift_date),
                    $upload->biometric_site_id  // Pass the biometric site ID
                );

                if ($result['matched']) {
                    $stats['matched_employees']++;
                    $stats['processed'] += $result['records_processed'];
                    \Log::debug('Employee matched', ['name' => $normalizedName]);
                } else {
                    $stats['unmatched_names'][] = $employeeRecords->first()['name'];
                    \Log::warning('Employee not matched', [
                        'normalized_name' => $normalizedName,
                        'original_name' => $employeeRecords->first()['name']
                    ]);
                }

                if (!empty($result['errors'])) {
                    $stats['errors'] = array_merge($stats['errors'], $result['errors']);
                }
            }

            // Update upload record
            $upload->update([
                'status' => 'completed',
                'total_records' => $stats['total_records'],
                'processed_records' => $stats['processed'],
                'matched_employees' => $stats['matched_employees'],
                'unmatched_names' => count($stats['unmatched_names']),
                'unmatched_names_list' => $stats['unmatched_names'],
                'date_warnings' => $stats['date_warnings'],
                'dates_found' => $stats['dates_found'],
            ]);

            return $stats;

        } catch (\Exception $e) {
            $upload->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Save raw biometric records to database for audit trail.
     *
     * @param Collection $records
     * @param AttendanceUpload $upload
     * @return void
     */
    protected function saveBiometricRecords(Collection $records, AttendanceUpload $upload): void
    {
        $biometricRecords = [];

        foreach ($records as $record) {
            // Find user by name
            $normalizedName = $this->parser->normalizeName($record['name']);
            $user = $this->findUserByName($normalizedName, collect([$record]));

            if (!$user) {
                // Skip records for unmatched users, but log them
                \Log::warning('Skipping biometric record for unmatched user', [
                    'name' => $record['name'],
                    'normalized_name' => $normalizedName,
                    'datetime' => $record['datetime']->format('Y-m-d H:i:s'),
                ]);
                continue;
            }

            $biometricRecords[] = [
                'user_id' => $user->id,
                'attendance_upload_id' => $upload->id,
                'site_id' => $upload->biometric_site_id,
                'employee_name' => $record['name'], // Original name from device
                'datetime' => $record['datetime'],
                'record_date' => $record['datetime']->format('Y-m-d'),
                'record_time' => $record['datetime']->format('H:i:s'),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        // Bulk insert for performance
        if (!empty($biometricRecords)) {
            BiometricRecord::insert($biometricRecords);
            \Log::info('Saved biometric records', [
                'count' => count($biometricRecords),
                'upload_id' => $upload->id,
            ]);
        }
    }

    /**
     * Validate that file dates match expected dates for the shift.
     *
     * @param Collection $records
     * @param Carbon $shiftDate
     * @return array
     */
    protected function validateFileDates(Collection $records, Carbon $shiftDate): array
    {
        $warnings = [];
        $datesFound = [];

        // Collect all unique dates from records
        foreach ($records as $record) {
            $recordDate = $record['datetime']->format('Y-m-d');
            if (!in_array($recordDate, $datesFound)) {
                $datesFound[] = $recordDate;
            }
        }

        // For night shifts, we expect records from shift date and next day
        $expectedDates = [
            $shiftDate->format('Y-m-d'),
            $shiftDate->copy()->addDay()->format('Y-m-d'),
        ];

        // Check if any dates are outside expected range
        $unexpectedDates = array_diff($datesFound, $expectedDates);

        if (!empty($unexpectedDates)) {
            $warnings[] = sprintf(
                "File contains records from unexpected dates: %s. Expected dates: %s for shift date %s.",
                implode(', ', array_map(fn($d) => Carbon::parse($d)->format('M d, Y'), $unexpectedDates)),
                implode(', ', array_map(fn($d) => Carbon::parse($d)->format('M d, Y'), $expectedDates)),
                $shiftDate->format('M d, Y')
            );
        }

        // Log validation results
        \Log::info('Date validation completed', [
            'shift_date' => $shiftDate->format('Y-m-d'),
            'expected_dates' => $expectedDates,
            'dates_found' => $datesFound,
            'warnings' => $warnings,
        ]);

        return [
            'warnings' => $warnings,
            'dates_found' => $datesFound,
            'expected_dates' => $expectedDates,
        ];
    }

    /**
     * Process records for a single employee.
     * Auto-detects shift dates from time in records.
     *
     * @param string $normalizedName
     * @param Collection $records
     * @param Carbon $shiftDate (now used as reference date for the file, not the actual shift date)
     * @param int|null $biometricSiteId
     * @return array
     */
    protected function processEmployeeRecords(
        string $normalizedName,
        Collection $records,
        Carbon $shiftDate,
        ?int $biometricSiteId = null
    ): array {
        // Find user by matching name
        $user = $this->findUserByName($normalizedName, $records);

        if (!$user) {
            return [
                'matched' => false,
                'records_processed' => 0,
                'errors' => [],
            ];
        }

        // Group records by detected shift date
        // For night shifts (18:00-23:59), the shift date is the date of that time in record
        $shiftGroups = $this->groupRecordsByShiftDate($records, $user);

        $processedCount = 0;
        foreach ($shiftGroups as $detectedShiftDate => $shiftRecords) {
            $result = $this->processShift($user, $shiftRecords, Carbon::parse($detectedShiftDate), $biometricSiteId);
            if ($result['processed']) {
                $processedCount++;
            }
        }

        return [
            'matched' => true,
            'records_processed' => $processedCount,
            'errors' => [],
        ];
    }

    /**
     * Determine shift type based on employee's schedule.
     *
     * @param EmployeeSchedule|null $schedule
     * @return string 'morning', 'afternoon', 'evening', 'night', or 'graveyard'
     */
    protected function determineShiftType($schedule): string
    {
        if (!$schedule) {
            return 'unknown';
        }

        $scheduledHour = Carbon::parse($schedule->scheduled_time_in)->hour;

        // Morning shift: 05:00 - 11:59
        if ($scheduledHour >= 5 && $scheduledHour < 12) {
            return 'morning';
        }
        // Afternoon shift: 12:00 - 17:59
        elseif ($scheduledHour >= 12 && $scheduledHour < 18) {
            return 'afternoon';
        }
        // Evening shift: 18:00 - 21:59
        elseif ($scheduledHour >= 18 && $scheduledHour < 22) {
            return 'evening';
        }
        // Night shift: 22:00 - 23:59
        elseif ($scheduledHour >= 22) {
            return 'night';
        }
        // Graveyard shift: 00:00 - 04:59 (starts after midnight)
        else {
            return 'graveyard';
        }
    }

    /**
     * Determine if shift spans to next day by comparing scheduled times.
     *
     * @param EmployeeSchedule $schedule
     * @return bool True if time out is on next day
     */
    protected function isNextDayShift($schedule): bool
    {
        $schedTimeIn = Carbon::parse($schedule->scheduled_time_in);
        $schedTimeOut = Carbon::parse($schedule->scheduled_time_out);

        // Special case: Graveyard shift starting at 00:00-04:59 and ending later
        // These are shifts that start late evening (around 22:00-23:59) and end in the morning
        // Example: 00:00-09:00 actually means late evening to 9 AM next day
        $schedInHour = $schedTimeIn->hour;
        $schedOutHour = $schedTimeOut->hour;

        if ($schedInHour >= 0 && $schedInHour < 5 && $schedOutHour > $schedInHour) {
            // This is a graveyard shift (00:00-04:59 start time) that ends later in morning
            // Treat it as next-day shift
            return true;
        }

        // If time out <= time in, shift spans to next day
        // Examples:
        // - 15:00 to 00:00: 00:00 <= 15:00 = true (next day)
        // - 22:00 to 07:00: 07:00 <= 22:00 = true (next day)
        // - 07:00 to 17:00: 17:00 <= 07:00 = false (same day)
        return $schedTimeOut->lessThanOrEqualTo($schedTimeIn);
    }

    /**
     * Group records by detected shift date based on employee's actual schedule.
     *
     * UNIVERSAL LOGIC:
     * 1. Check if shift spans to next day (time_out <= time_in)
     * 2. If SAME DAY shift: All records go to their actual date
     * 3. If NEXT DAY shift: Records before scheduled time in go to PREVIOUS day
     *
     * Examples:
     * - 07:00-17:00 (same day): All records on their date
     * - 15:00-00:00 (next day): Records 00:00-14:59 go to previous day
     * - 22:00-07:00 (next day): Records 00:00-21:59 go to previous day
     * - 01:00-11:00 (same day): All records on their date
     *
     * @param Collection $records
     * @param User $user
     * @return array
     */
    protected function groupRecordsByShiftDate(Collection $records, User $user): array
    {
        $groups = [];

        // Get user's active schedule to determine shift pattern
        $schedule = $user->employeeSchedules()->where('is_active', true)->first();

        if (!$schedule) {
            // No schedule, group by actual date
            foreach ($records as $record) {
                $shiftDate = $record['datetime']->format('Y-m-d');
                if (!isset($groups[$shiftDate])) {
                    $groups[$shiftDate] = collect();
                }
                $groups[$shiftDate]->push($record);
            }
            return $groups;
        }

        $isNextDayShift = $this->isNextDayShift($schedule);
        $scheduledHour = Carbon::parse($schedule->scheduled_time_in)->hour;
        $scheduledOutHour = Carbon::parse($schedule->scheduled_time_out)->hour;

        // Sort records by datetime to process chronologically
        $sortedRecords = $records->sortBy(function ($record) {
            return $record['datetime']->timestamp;
        });

        foreach ($sortedRecords as $record) {
            $datetime = $record['datetime'];
            $hour = $datetime->hour;

            if (!$isNextDayShift) {
                // SAME DAY SHIFT: All records go to their actual date
                // Examples: 07:00-17:00, 01:00-11:00
                $shiftDate = $datetime->format('Y-m-d');
            } else {
                // NEXT DAY SHIFT: Determine which shift date based on time

                // Special case for graveyard shifts (00:00-09:00 type schedules)
                // These start late evening (20:00-23:59) and end next morning
                if ($scheduledHour >= 0 && $scheduledHour < 5) {
                    // For 00:00-09:00 type shifts:
                    // - Records from 20:00-23:59 = time in for CURRENT day's shift
                    // - Records from 00:00 to scheduled out time = time out from PREVIOUS day's shift
                    if ($hour >= 20) {
                        // Late evening scan = time in for this shift
                        $shiftDate = $datetime->format('Y-m-d');
                    } elseif ($hour <= $scheduledOutHour) {
                        // Morning scan at or before scheduled out = time out from yesterday's shift
                        $shiftDate = $datetime->copy()->subDay()->format('Y-m-d');
                    } else {
                        // Other times during the day (between scheduled out and 20:00)
                        $shiftDate = $datetime->format('Y-m-d');
                    }
                } else {
                    // Regular next-day shifts (e.g., 15:00-00:00, 22:00-07:00)
                    if ($hour < $scheduledHour) {
                        // This record is BEFORE the shift start time, must be time out from previous day
                        $shiftDate = $datetime->copy()->subDay()->format('Y-m-d');
                    } else {
                        // This record is AT or AFTER shift start time, belongs to current day
                        $shiftDate = $datetime->format('Y-m-d');
                    }
                }
            }

            if (!isset($groups[$shiftDate])) {
                $groups[$shiftDate] = collect();
            }
            $groups[$shiftDate]->push($record);
        }

        return $groups;
    }

    /**
     * Process a single shift for an employee.
     *
     * @param User $user
     * @param Collection $records
     * @param Carbon $detectedShiftDate
     * @param int|null $biometricSiteId
     * @return array
     */
    protected function processShift(
        User $user,
        Collection $records,
        Carbon $detectedShiftDate,
        ?int $biometricSiteId = null
    ): array {
        // Get active schedule for this date
        $schedule = $user->employeeSchedules()
            ->forDate($detectedShiftDate)
            ->where('is_active', true)
            ->first();

        if (!$schedule) {
            return [
                'processed' => false,
                'error' => "No active schedule found for {$user->name} on {$detectedShiftDate->format('Y-m-d')}",
            ];
        }

        // Check if employee works on this day
        $dayName = $detectedShiftDate->format('l'); // Monday, Tuesday, etc.
        if (!$schedule->worksOnDay($dayName)) {
            return [
                'processed' => false,
                'error' => null, // Not an error, just doesn't work this day
            ];
        }

        // Process attendance for this shift
        $result = $this->processAttendance($user, $schedule, $records, $detectedShiftDate, $biometricSiteId);

        return [
            'processed' => $result['matched'] ?? false,
            'error' => !empty($result['errors']) ? implode(', ', $result['errors']) : null,
        ];
    }

    /**
     * Process attendance for both time in and time out.
     *
     * @param User $user
     * @param EmployeeSchedule $schedule
     * @param Collection $records
     * @param Carbon $shiftDate (the detected shift date from time in record)
     * @param int|null $biometricSiteId
     * @return array
     */
    protected function processAttendance(
        User $user,
        EmployeeSchedule $schedule,
        Collection $records,
        Carbon $shiftDate,
        ?int $biometricSiteId = null
    ): array {
        // Build scheduled time in datetime
        $scheduledTimeIn = Carbon::parse($shiftDate->format('Y-m-d') . ' ' . $schedule->scheduled_time_in);

        // Time in is always on the shift date itself
        $expectedTimeInDate = $shiftDate->copy();

        // Determine if time out is next day using the SAME logic as isNextDayShift()
        $schedTimeIn = Carbon::parse($schedule->scheduled_time_in);
        $schedTimeOut = Carbon::parse($schedule->scheduled_time_out);

        // Check for graveyard shift pattern (00:00-04:59 start time)
        $schedInHour = $schedTimeIn->hour;
        $schedOutHour = $schedTimeOut->hour;
        $isNextDayTimeOut = false;

        if ($schedInHour >= 0 && $schedInHour < 5 && $schedOutHour > $schedInHour) {
            // Graveyard shift (00:00-04:59 start time) that ends later in morning
            $isNextDayTimeOut = true;
        } else {
            // Standard check: time out <= time in means next day
            $isNextDayTimeOut = $schedTimeOut->lessThanOrEqualTo($schedTimeIn);
        }

        // Time out is next day if scheduled time out <= scheduled time in
        $expectedTimeOutDate = $shiftDate->copy();
        if ($isNextDayTimeOut) {
            $expectedTimeOutDate->addDay();
        }

        // Build scheduled time out datetime
        $scheduledTimeOut = Carbon::parse($shiftDate->format('Y-m-d') . ' ' . $schedule->scheduled_time_out);
        if ($isNextDayTimeOut) {
            $scheduledTimeOut->addDay();
        }

        // Determine time in search range based on scheduled time
        $scheduledHour = Carbon::parse($schedule->scheduled_time_in)->hour;

        // Find time in record based on shift pattern
        if (!$isNextDayTimeOut) {
            // SAME DAY SHIFT: Find earliest record on shift date (any hour)
            // Examples: 07:00-17:00, 01:00-10:00
            $timeInRecord = $this->parser->findTimeInRecord($records, $expectedTimeInDate);
        } else {
            // NEXT DAY SHIFT: Use time range based on shift start time
            // This helps distinguish between time in and time out records
            if ($scheduledHour >= 0 && $scheduledHour < 5) {
                // Graveyard shift (00:00-04:59) spanning to next day
                // Time in happens PREVIOUS EVENING (18:00-23:59)
                // All records are grouped to the shift date, so time in is 18-23 on shift date
                $timeInRecord = $this->parser->findTimeInRecordByTimeRange($records, $expectedTimeInDate, 18, 23);
            } elseif ($scheduledHour >= 5 && $scheduledHour < 12) {
                // Morning shift spanning to next day (rare but possible)
                $timeInRecord = $this->parser->findTimeInRecordByTimeRange($records, $expectedTimeInDate, 5, 11);
            } elseif ($scheduledHour >= 12 && $scheduledHour < 18) {
                // Afternoon shift spanning to next day (e.g., 15:00-00:00)
                $timeInRecord = $this->parser->findTimeInRecordByTimeRange($records, $expectedTimeInDate, 12, 17);
            } else {
                // Evening/Night shift spanning to next day (e.g., 22:00-07:00)
                $timeInRecord = $this->parser->findTimeInRecordByTimeRange($records, $expectedTimeInDate, 18, 23);
            }
        }

        // Find time out record
        // For graveyard next-day shifts (00:00-04:59), time out is in morning range (0 to scheduled out hour)
        // All records are already grouped to the shift date, so we need time range to distinguish
        if ($isNextDayTimeOut && $scheduledHour >= 0 && $scheduledHour < 5) {
            // Graveyard shift: Time out is 00:00 to scheduled out hour on shift date
            $scheduledOutHour = Carbon::parse($schedule->scheduled_time_out)->hour;
            $timeOutRecord = $this->parser->findTimeOutRecordByTimeRange($records, $expectedTimeOutDate, 0, $scheduledOutHour);
        } else {
            // Regular shifts or other next-day shifts: Use standard logic
            $timeOutRecord = $this->parser->findTimeOutRecord($records, $expectedTimeOutDate);
        }

        // Get or create attendance record
        $attendance = Attendance::firstOrCreate(
            [
                'user_id' => $user->id,
                'shift_date' => $shiftDate,
            ],
            [
                'employee_schedule_id' => $schedule->id,
                'scheduled_time_in' => $schedule->scheduled_time_in,
                'scheduled_time_out' => $schedule->scheduled_time_out,
                'status' => 'ncns', // Default to NCNS
            ]
        );

        // Process time in
        if ($timeInRecord) {
            $actualTimeIn = $timeInRecord['datetime'];
            $tardyMinutes = $scheduledTimeIn->diffInMinutes($actualTimeIn, false);
            $status = $this->determineTimeInStatus($tardyMinutes);

            $isCrossSite = $biometricSiteId && $schedule->site_id && ($biometricSiteId != $schedule->site_id);

            $attendance->update([
                'actual_time_in' => $actualTimeIn,
                'bio_in_site_id' => $biometricSiteId,
                'status' => $status,
                'tardy_minutes' => $tardyMinutes > 0 ? $tardyMinutes : null,
                'is_cross_site_bio' => $isCrossSite,
            ]);
        }

        // Process time out
        if ($timeOutRecord) {
            $actualTimeOut = $timeOutRecord['datetime'];
            $undertimeMinutes = $scheduledTimeOut->diffInMinutes($actualTimeOut, false);

            $bioInSiteId = $attendance->bio_in_site_id;
            $isCrossSite = $attendance->is_cross_site_bio ||
                          ($biometricSiteId && $schedule->site_id && ($biometricSiteId != $schedule->site_id)) ||
                          ($biometricSiteId && $bioInSiteId && ($biometricSiteId != $bioInSiteId));

            $updates = [
                'actual_time_out' => $actualTimeOut,
                'bio_out_site_id' => $biometricSiteId,
                'is_cross_site_bio' => $isCrossSite,
            ];

            // Check for undertime (> 60 minutes early)
            if ($undertimeMinutes < -60) {
                $updates['undertime_minutes'] = abs($undertimeMinutes);
                if ($attendance->status === 'on_time') {
                    $updates['status'] = 'undertime';
                }
            }

            // Update status if no time in but has time out
            if (!$attendance->actual_time_in) {
                $updates['status'] = 'failed_bio_in';
            }

            $attendance->update($updates);
        } else {
            // No time out record
            if ($attendance->actual_time_in) {
                $attendance->update(['status' => 'failed_bio_out']);
            }
        }

        return [
            'matched' => true,
            'records_processed' => 1,
            'errors' => [],
        ];
    }

    /**
     * Process Time In phase.
     *
     * @param User $user
     * @param EmployeeSchedule $schedule
     * @param Collection $records
     * @param Carbon $shiftDate
     * @param int|null $biometricSiteId
     * @return array
     */
    protected function processTimeIn(
        User $user,
        EmployeeSchedule $schedule,
        Collection $records,
        Carbon $shiftDate,
        ?int $biometricSiteId = null
    ): array {
        // Build scheduled time in datetime
        $scheduledTimeIn = Carbon::parse($shiftDate->format('Y-m-d') . ' ' . $schedule->scheduled_time_in);

        // Time in is always on the shift date itself
        // E.g., Nov 5 shift -> time in on Nov 5
        $expectedTimeInDate = $shiftDate->copy();

        // Find time in record on expected date (earliest record on shift date)
        $timeInRecord = $this->parser->findTimeInRecord($records, $expectedTimeInDate);

        // Get or create attendance record
        $attendance = Attendance::firstOrCreate(
            [
                'user_id' => $user->id,
                'shift_date' => $shiftDate,
            ],
            [
                'employee_schedule_id' => $schedule->id,
                'scheduled_time_in' => $schedule->scheduled_time_in,
                'scheduled_time_out' => $schedule->scheduled_time_out,
                'status' => 'ncns', // Default to NCNS
            ]
        );        if ($timeInRecord) {
            // Calculate tardiness
            $actualTimeIn = $timeInRecord['datetime'];
            $tardyMinutes = $scheduledTimeIn->diffInMinutes($actualTimeIn, false);

            // Determine status based on rules
            $status = $this->determineTimeInStatus($tardyMinutes);

            // Check if employee bio'd at a different site than assigned
            $isCrossSite = $biometricSiteId && $schedule->site_id && ($biometricSiteId != $schedule->site_id);

            $attendance->update([
                'actual_time_in' => $actualTimeIn,
                'bio_in_site_id' => $biometricSiteId,
                'status' => $status,
                'tardy_minutes' => $tardyMinutes > 0 ? $tardyMinutes : null,
                'is_cross_site_bio' => $isCrossSite,
            ]);
        } else {
            // No time in record found
            if (!$attendance->is_advised) {
                $attendance->update(['status' => 'ncns']);
            }
        }

        return [
            'matched' => true,
            'records_processed' => 1,
            'errors' => [],
        ];
    }

    /**
     * Process Time Out phase.
     *
     * @param User $user
     * @param EmployeeSchedule $schedule
     * @param Collection $records
     * @param Carbon $shiftDate
     * @param int|null $biometricSiteId
     * @return array
     */
    protected function processTimeOut(
        User $user,
        EmployeeSchedule $schedule,
        Collection $records,
        Carbon $shiftDate,
        ?int $biometricSiteId = null
    ): array {
        // Get attendance record
        $attendance = Attendance::where('user_id', $user->id)
            ->where('shift_date', $shiftDate)
            ->first();

        if (!$attendance) {
            // Create attendance if it doesn't exist (shouldn't happen normally)
            $attendance = Attendance::create([
                'user_id' => $user->id,
                'employee_schedule_id' => $schedule->id,
                'shift_date' => $shiftDate,
                'scheduled_time_in' => $schedule->scheduled_time_in,
                'scheduled_time_out' => $schedule->scheduled_time_out,
                'status' => 'failed_bio_in',
            ]);
        }

        // Build scheduled time out datetime (could be next day for night shift)
        $scheduledTimeOut = Carbon::parse($shiftDate->format('Y-m-d') . ' ' . $schedule->scheduled_time_out);

        // Determine expected date for time out (usually next day for night shifts)
        $expectedTimeOutDate = $shiftDate->copy();
        if ($schedule->isNightShift()) {
            $expectedTimeOutDate->addDay();
        }

        // Find time out record on expected date
        $timeOutRecord = $this->parser->findTimeOutRecord($records, $expectedTimeOutDate);

        if ($timeOutRecord) {
            $actualTimeOut = $timeOutRecord['datetime'];
            $undertimeMinutes = $scheduledTimeOut->diffInMinutes($actualTimeOut, false);

            // Check if employee bio'd out at a different site
            $bioInSiteId = $attendance->bio_in_site_id;
            $isCrossSite = $attendance->is_cross_site_bio ||
                          ($biometricSiteId && $schedule->site_id && ($biometricSiteId != $schedule->site_id)) ||
                          ($biometricSiteId && $bioInSiteId && ($biometricSiteId != $bioInSiteId));

            // Update attendance with time out info
            $updates = [
                'actual_time_out' => $actualTimeOut,
                'bio_out_site_id' => $biometricSiteId,
                'is_cross_site_bio' => $isCrossSite,
            ];

            // Check for undertime (> 60 minutes early)
            if ($undertimeMinutes < -60) { // Negative means left early
                $updates['undertime_minutes'] = abs($undertimeMinutes);
                if ($attendance->status === 'on_time') {
                    $updates['status'] = 'undertime';
                }
            }

            $attendance->update($updates);
        } else {
            // No time out record found
            if ($attendance->actual_time_in) {
                $attendance->update(['status' => 'failed_bio_out']);
            } else {
                // Neither time in nor time out - check if they showed up in the time out records
                $anyRecord = $records->first();
                if ($anyRecord) {
                    $attendance->update([
                        'status' => 'failed_bio_in',
                        'actual_time_out' => $anyRecord['datetime'],
                    ]);
                }
            }
        }

        return [
            'matched' => true,
            'records_processed' => 1,
            'errors' => [],
        ];
    }

    /**
     * Determine status based on tardiness minutes.
     *
     * @param int $tardyMinutes Positive if late, negative if early
     * @return string
     */
    protected function determineTimeInStatus(int $tardyMinutes): string
    {
        if ($tardyMinutes <= 0) {
            return 'on_time';
        }

        if ($tardyMinutes >= 1 && $tardyMinutes <= 15) {
            return 'tardy';
        }

        // More than 15 minutes late
        return 'half_day_absence';
    }

    /**
     * Find user by normalized name with smart matching.
     * Handles biometric naming convention:
     * - Single last name if unique: "Rosel"
     * - Last name + first initial if duplicate: "Cabarliza A"
     * - Last name + 2 letters if first initial conflicts: "Robinios Je" vs "Robinios Jo"
     * When multiple users share the same last name, considers shift timing for better matching.
     *
     * @param string $normalizedName
     * @param Collection|null $records Optional: employee records to help with shift-based matching
     * @return User|null
     */
    protected function findUserByName(string $normalizedName, ?Collection $records = null): ?User
    {
        $users = User::all();
        $matches = [];

        foreach ($users as $user) {
            $lastName = strtolower(trim($user->last_name));
            $firstName = strtolower(trim($user->first_name));
            $firstInitial = strtolower(substr($firstName, 0, 1));
            $firstTwoLetters = strtolower(substr($firstName, 0, 2));

            // Pattern 1: Just last name (e.g., "rosel")
            if ($normalizedName === $lastName) {
                $matches[] = $user;
            }

            // Pattern 2: Last name + first 2 letters (e.g., "robinios je")
            $lastNameWithTwoLetters = $lastName . ' ' . $firstTwoLetters;
            if ($normalizedName === $lastNameWithTwoLetters) {
                return $user; // This is a specific match, return immediately
            }

            // Pattern 3: Last name + first initial (e.g., "cabarliza a")
            $lastNameWithInitial = $lastName . ' ' . $firstInitial;
            if ($normalizedName === $lastNameWithInitial) {
                // Check if there are other users with same last name and first initial
                $sameInitialUsers = $users->filter(function ($u) use ($lastName, $firstInitial) {
                    return strtolower(trim($u->last_name)) === $lastName &&
                           strtolower(substr(trim($u->first_name), 0, 1)) === $firstInitial;
                });

                // If multiple users share the same last name and first initial, use smart matching
                if ($sameInitialUsers->count() > 1 && $records && $records->count() > 0) {
                    $bestMatch = $this->findBestUserMatch($sameInitialUsers->all(), $records);
                    if ($bestMatch) {
                        return $bestMatch;
                    }
                }

                return $user;
            }
        }

        // If we have multiple matches with same last name, try to disambiguate using shift timing
        if (count($matches) > 1 && $records && $records->count() > 0) {
            return $this->findBestUserMatch($matches, $records);
        }

        // Return single match or first match if multiple
        return $matches[0] ?? null;
    }

    /**
     * Find best user match when multiple users share the same last name.
     * Uses the earliest biometric record time to match with user's shift schedule.
     *
     * @param array $users
     * @param Collection $records
     * @return User|null
     */
    protected function findBestUserMatch(array $users, Collection $records): ?User
    {
        // Return null if no users provided
        if (empty($users)) {
            return null;
        }

        // Find the earliest record to determine shift type
        $earliestRecord = $records->sortBy(function ($record) {
            return $record['datetime']->timestamp;
        })->first();

        if (!$earliestRecord) {
            return $users[0] ?? null;
        }

        $earliestHour = $earliestRecord['datetime']->hour;

        // Match users based on their schedule's shift time
        foreach ($users as $user) {
            $schedule = $user->employeeSchedules()->where('is_active', true)->first();
            if (!$schedule) {
                continue;
            }

            $scheduledHour = Carbon::parse($schedule->scheduled_time_in)->hour;

            // Morning shift (06:00-11:59)
            if ($earliestHour >= 6 && $earliestHour < 12) {
                if ($scheduledHour >= 6 && $scheduledHour < 12) {
                    return $user;
                }
            }
            // Afternoon shift (12:00-17:59)
            elseif ($earliestHour >= 12 && $earliestHour < 18) {
                if ($scheduledHour >= 12 && $scheduledHour < 18) {
                    return $user;
                }
            }
            // Evening/Night shift (18:00-05:59)
            else {
                if ($scheduledHour >= 18 || $scheduledHour < 6) {
                    return $user;
                }
            }
        }

        // If no schedule-based match found, return first user (or null if empty)
        return $users[0] ?? null;
    }
}
