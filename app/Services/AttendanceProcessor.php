<?php

namespace App\Services;

use App\Models\User;
use App\Models\Attendance;
use App\Models\EmployeeSchedule;
use App\Models\AttendanceUpload;
use App\Models\BiometricRecord;
use App\Models\AttendancePoint;
use App\Models\LeaveRequest;
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
            DB::beginTransaction();

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
                'non_work_day_scans' => [], // Track scans on non-scheduled days
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

                    // Collect non-work day scans
                    if (!empty($result['skipped_non_work_days'])) {
                        $stats['non_work_day_scans'][] = [
                            'employee' => $employeeRecords->first()['name'],
                            'dates' => $result['skipped_non_work_days'],
                        ];
                    }

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

            // Log summary of non-work day scans for admin review
            if (!empty($stats['non_work_day_scans'])) {
                \Log::warning('Biometric scans detected on non-scheduled work days', [
                    'upload_id' => $upload->id,
                    'count' => count($stats['non_work_day_scans']),
                    'details' => $stats['non_work_day_scans'],
                ]);
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

            // Detect and create NCNS records for absent employees
            $this->detectAbsentEmployees($upload, $records);

            // NOTE: Attendance points are NOT auto-generated here
            // Points will be generated only after admin verifies the attendance records
            // Admin can trigger point generation via the review/verification page

            DB::commit();

            \Log::info('Attendance upload processing completed successfully', [
                'upload_id' => $upload->id,
                'total_records' => $stats['total_records'],
                'matched_employees' => $stats['matched_employees'],
            ]);

            return $stats;

        } catch (\Exception $e) {
            DB::rollBack();

            $upload->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            \Log::error('Attendance upload processing failed', [
                'upload_id' => $upload->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
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
     * Check if user has an approved leave request for the given date.
     *
     * @param User $user
     * @param Carbon $date
     * @return LeaveRequest|null
     */
    protected function checkApprovedLeave(User $user, Carbon $date): ?LeaveRequest
    {
        return LeaveRequest::where('user_id', $user->id)
            ->where('status', 'approved')
            ->where('start_date', '<=', $date)
            ->where('end_date', '>=', $date)
            ->first();
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
        $skippedNonWorkDays = [];

        foreach ($shiftGroups as $detectedShiftDate => $shiftRecords) {
            $result = $this->processShift($user, $shiftRecords, Carbon::parse($detectedShiftDate), $biometricSiteId);
            if ($result['processed']) {
                $processedCount++;
            } elseif ($result['skipped_non_work_day']) {
                // Track dates where employee has scans but isn't scheduled to work
                $skippedNonWorkDays[] = [
                    'date' => $detectedShiftDate,
                    'day_name' => Carbon::parse($detectedShiftDate)->format('l'),
                    'scan_count' => $shiftRecords->count(),
                ];
            }
        }

        // Log non-work day scans for visibility
        if (!empty($skippedNonWorkDays)) {
            \Log::info('Biometric scans found on non-work days', [
                'user' => $user->name,
                'non_work_days' => $skippedNonWorkDays,
            ]);
        }

        return [
            'matched' => true,
            'records_processed' => $processedCount,
            'skipped_non_work_days' => $skippedNonWorkDays,
            'errors' => [],
        ];
    }

    /**
     * Public wrapper to allow reprocessing from external callers (artisan commands, etc.).
     *
     * @param string $normalizedName
     * @param Collection $records
     * @param Carbon $shiftDate
     * @return array
     */
    public function reprocessEmployeeRecords(string $normalizedName, Collection $records, Carbon $shiftDate): array
    {
        return $this->processEmployeeRecords($normalizedName, $records, $shiftDate);
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

        $schedInHour = $schedTimeIn->hour;
        $schedOutHour = $schedTimeOut->hour;

        // Special case: Graveyard shift starting at 00:00-04:59 and ending later same morning
        // Example: 00:00-09:00 means midnight to 9 AM on SAME day
        // This is NOT a next-day shift because both times are on the same calendar date
        if ($schedInHour >= 0 && $schedInHour < 5 && $schedOutHour > $schedInHour) {
            // Both time in and time out are in early morning hours on same day
            return false;
        }

        // Standard check: If time out <= time in, shift spans to next day
        // Examples:
        // - 22:00 to 07:00: 07:00 < 22:00 = true (next day) - Night shift
        // - 15:00 to 00:00: 00:00 < 15:00 = true (next day) - Afternoon shift
        // - 07:00 to 17:00: 17:00 > 07:00 = false (same day) - Morning shift
        return $schedTimeOut->lessThanOrEqualTo($schedTimeIn);
    }

    /**
     * Group records by detected shift date based on employee's actual schedule.
     *
     * SHIFT TYPE LOGIC:
     * 1. SAME DAY shifts (e.g., 08:00-17:00, 00:00-09:00):
     *    - All records stay on their actual date
     *
     * 2. NEXT DAY shifts (e.g., 22:00-07:00, 15:00-00:00):
     *    - Records before shift start time go to PREVIOUS day (they're time out from yesterday)
     *    - Records at/after shift start time stay on current day
     *
     * Examples:
     * - 08:00-17:00 (Morning, same day): All records on their date
     * - 00:00-09:00 (Graveyard, same day): All records on their date
     * - 22:00-07:00 (Night, next day): Records 00:00-21:59 go to previous day
     * - 15:00-00:00 (Afternoon, next day): Records 00:00-14:59 go to previous day
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
                // Examples: 08:00-17:00 (morning), 00:00-09:00 (graveyard), 14:00-20:00 (afternoon)
                $shiftDate = $datetime->format('Y-m-d');
            } else {
                // NEXT DAY SHIFT: Determine which shift date based on time
                // Examples: 22:00-07:00 (night), 15:00-00:00 (afternoon extending to midnight)

                // Add tolerance: scans within 1 hour before shift start are considered part of that shift
                // This handles early scans like 21:55 for a 22:00 shift
                $scheduledTime = Carbon::parse($datetime->format('Y-m-d') . ' ' . $schedule->scheduled_time_in);
                $minutesBeforeShift = $datetime->diffInMinutes($scheduledTime, false);

                // If scan is within 60 minutes before scheduled time, it belongs to TODAY's shift
                if ($minutesBeforeShift >= 0 && $minutesBeforeShift <= 60) {
                    $shiftDate = $datetime->format('Y-m-d');
                }
                // If scan is clearly before shift time (more than 1 hour), it's from YESTERDAY's shift
                elseif ($hour < $scheduledHour) {
                    // Record is BEFORE the shift start time = time out from previous day's shift
                    // Example: For 22:00-07:00 shift, a 05:00 record belongs to yesterday's shift
                    $shiftDate = $datetime->copy()->subDay()->format('Y-m-d');
                } else {
                    // Record is AT or AFTER shift start time = belongs to current day's shift
                    // Example: For 22:00-07:00 shift, a 22:30 record belongs to today's shift
                    $shiftDate = $datetime->format('Y-m-d');
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
            // Create a record for non-work day attendance (e.g., overtime, special work)
            $this->createNonWorkDayAttendance($user, $schedule, $records, $detectedShiftDate, $biometricSiteId);

            return [
                'processed' => false,
                'skipped_non_work_day' => true,
                'error' => null, // Not an error, just doesn't work this day
            ];
        }

        // Check if user has an approved leave request for this date
        $approvedLeave = $this->checkApprovedLeave($user, $detectedShiftDate);
        if ($approvedLeave) {
            // Create attendance record with on_leave status
            $this->createLeaveAttendance($user, $schedule, $detectedShiftDate, $approvedLeave);

            return [
                'processed' => true,
                'on_leave' => true,
                'error' => null,
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
     * Create attendance record for employee on approved leave.
     *
     * @param User $user
     * @param EmployeeSchedule $schedule
     * @param Carbon $shiftDate
     * @param LeaveRequest $leaveRequest
     * @return void
     */
    protected function createLeaveAttendance(
        User $user,
        EmployeeSchedule $schedule,
        Carbon $shiftDate,
        LeaveRequest $leaveRequest
    ): void {
        // Check if attendance record already exists
        $attendance = Attendance::where('user_id', $user->id)
            ->where('shift_date', $shiftDate)
            ->first();

        if ($attendance) {
            // If record exists and is already verified, don't modify
            if ($attendance->admin_verified) {
                return;
            }

            // Update existing record to on_leave status
            $attendance->update([
                'status' => 'on_leave',
                'leave_request_id' => $leaveRequest->id,
                'admin_verified' => true, // Auto-verify leave records
                'notes' => "Employee on approved {$leaveRequest->leave_type} leave. Leave request #{$leaveRequest->id}.",
            ]);
        } else {
            // Create new attendance record with on_leave status
            Attendance::create([
                'user_id' => $user->id,
                'employee_schedule_id' => $schedule->id,
                'leave_request_id' => $leaveRequest->id,
                'shift_date' => $shiftDate,
                'scheduled_time_in' => $schedule->scheduled_time_in,
                'scheduled_time_out' => $schedule->scheduled_time_out,
                'status' => 'on_leave',
                'admin_verified' => true, // Auto-verify leave records
                'notes' => "Employee on approved {$leaveRequest->leave_type} leave. Leave request #{$leaveRequest->id}.",
            ]);
        }

        \Log::info('Created on_leave attendance record', [
            'user_id' => $user->id,
            'user_name' => $user->name,
            'shift_date' => $shiftDate->format('Y-m-d'),
            'leave_request_id' => $leaveRequest->id,
            'leave_type' => $leaveRequest->leave_type,
        ]);
    }

    /**
     * Create attendance record for non-scheduled work day (overtime/special day).
     *
     * @param User $user
     * @param EmployeeSchedule $schedule
     * @param Collection $records
     * @param Carbon $shiftDate
     * @param int|null $biometricSiteId
     * @return void
     */
    protected function createNonWorkDayAttendance(
        User $user,
        EmployeeSchedule $schedule,
        Collection $records,
        Carbon $shiftDate,
        ?int $biometricSiteId = null
    ): void {
        // Get or create attendance record
        $attendance = Attendance::firstOrNew([
            'user_id' => $user->id,
            'shift_date' => $shiftDate,
        ]);

        // If record already exists and is verified, don't modify
        if ($attendance->exists && $attendance->admin_verified) {
            return;
        }

        // Find TIME IN and TIME OUT from biometric scans
        $sortedRecords = $records->sortBy(fn($r) => $r['datetime']->timestamp);
        $timeInRecord = $sortedRecords->first();
        $timeOutRecord = $sortedRecords->count() > 1 ? $sortedRecords->last() : null;

        // Build scheduled times (for reference, even though not a work day)
        $scheduledTimeIn = Carbon::parse($shiftDate->format('Y-m-d') . ' ' . $schedule->scheduled_time_in);
        $scheduledTimeOut = Carbon::parse($shiftDate->format('Y-m-d') . ' ' . $schedule->scheduled_time_out);

        // Adjust scheduled time out for next-day shifts
        if ($this->isNextDayShift($schedule)) {
            $scheduledTimeOut->addDay();
        }

        // Set values
        $attendance->scheduled_time_in = $scheduledTimeIn;
        $attendance->scheduled_time_out = $scheduledTimeOut;
        $attendance->actual_time_in = $timeInRecord ? $timeInRecord['datetime'] : null;
        $attendance->actual_time_out = $timeOutRecord ? $timeOutRecord['datetime'] : null;
        $attendance->bio_in_site_id = $biometricSiteId;
        $attendance->bio_out_site_id = $biometricSiteId;
        $attendance->status = 'non_work_day'; // Special status
        $attendance->admin_verified = false;

        // Add note about why this was created
        $dayName = $shiftDate->format('l');
        $attendance->notes = "Biometric scans detected on non-scheduled work day ({$dayName}). " .
                           "Employee has {$records->count()} scan(s) on this date. " .
                           "This may represent overtime, special work, or data issue. Requires verification.";

        $attendance->save();

        \Log::info('Created non-work day attendance record', [
            'user_id' => $user->id,
            'user_name' => $user->name,
            'shift_date' => $shiftDate->format('Y-m-d'),
            'day' => $dayName,
            'scan_count' => $records->count(),
        ]);
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
            // Early morning shift (00:00-04:59 start time) that ends later in morning on SAME day
            // Example: 00:00-09:00, 01:00-10:00
            // Both IN and OUT happen on the same calendar date
            $isNextDayTimeOut = false;
        } else {
            // Standard check: time out <= time in means next day
            // Example: 22:00-07:00 (time out 07:00 < time in 22:00, so next day)
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
            // SAME DAY SHIFT: Find earliest valid record on shift date
            // Pass scheduled time to filter out unreasonably early scans (>2 hours before)
            // Examples: 07:00-17:00, 01:00-10:00
            $timeInRecord = $this->parser->findTimeInRecord($records, $expectedTimeInDate, $schedule->scheduled_time_in);
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
        // Get the expected time out hour to help determine if this is a morning or evening time out
        $scheduledOutHour = Carbon::parse($schedule->scheduled_time_out)->hour;

        // For graveyard next-day shifts (00:00-04:59), time out is in morning range (0 to scheduled out hour)
        // All records are already grouped to the shift date, so we need time range to distinguish
        if ($isNextDayTimeOut && $scheduledHour >= 0 && $scheduledHour < 5) {
            // Graveyard shift: Time out is 00:00 to scheduled out hour on shift date
            $timeOutRecord = $this->parser->findTimeOutRecordByTimeRange($records, $expectedTimeOutDate, 0, $scheduledOutHour);
        } else {
            // Pass the expected hour and scheduled time out for smart matching
            // For multiple scans, this finds the one closest to scheduled time (ignores re-entries)
            $timeOutRecord = $this->parser->findTimeOutRecord(
                $records,
                $expectedTimeOutDate,
                $scheduledOutHour,
                $schedule->scheduled_time_out
            );
        }        // Get or create attendance record
        $attendance = Attendance::firstOrNew(
            [
                'user_id' => $user->id,
                'shift_date' => $shiftDate,
            ]
        );

        // If creating new record, set defaults
        if (!$attendance->exists) {
            $attendance->employee_schedule_id = $schedule->id;
            $attendance->scheduled_time_in = $schedule->scheduled_time_in;
            $attendance->scheduled_time_out = $schedule->scheduled_time_out;
            $attendance->status = 'ncns'; // Default to NCNS
            $attendance->save();
        }

        // Reset actual times to null at the start of processing
        // This ensures we don't carry over old values from previous processing
        $attendance->actual_time_in = null;
        $attendance->actual_time_out = null;
        $attendance->bio_in_site_id = null;
        $attendance->bio_out_site_id = null;
        $attendance->tardy_minutes = null;
        $attendance->undertime_minutes = null;
        $attendance->is_cross_site_bio = false;

        // Check if time in and time out are the same record (single bio scan)
        $sameRecord = false;
        if ($timeInRecord && $timeOutRecord) {
            $sameRecord = $timeInRecord['datetime']->equalTo($timeOutRecord['datetime']);
        }

        // If same record, determine if it's TIME IN or TIME OUT based on timing
        if ($sameRecord) {
            $scanTime = $timeInRecord['datetime'];

            // If scan is significantly after scheduled TIME OUT (more than 2 hours),
            // it's likely a very late TIME IN, not an early TIME OUT
            $hoursAfterScheduledOut = $scheduledTimeOut->diffInHours($scanTime, false);
            if ($hoursAfterScheduledOut > 2) {
                // Treat as very late TIME IN
                $timeOutRecord = null;
            } else {
                // Calculate midpoint between scheduled IN and OUT
                $midpoint = $scheduledTimeIn->copy()->addMinutes(
                    $scheduledTimeIn->diffInMinutes($scheduledTimeOut) / 2
                );

                // If scan is before midpoint, treat as TIME IN (closer to start of shift)
                // If scan is after midpoint, treat as TIME OUT (closer to end of shift)
                if ($scanTime->lessThan($midpoint)) {
                    $timeOutRecord = null;
                } else {
                    $timeInRecord = null;
                }
            }
        }

        // Process time in
        if ($timeInRecord) {
            $actualTimeIn = $timeInRecord['datetime'];
            $tardyMinutes = $scheduledTimeIn->diffInMinutes($actualTimeIn, false);

            // diffInMinutes with false gives positive if actualTimeIn is after scheduledTimeIn
            // We only want to set tardy_minutes if they are actually late (positive value)
            $gracePeriodMinutes = $schedule->grace_period_minutes ?? 15;
            $status = $this->determineTimeInStatus($tardyMinutes, $gracePeriodMinutes);

            $isCrossSite = $biometricSiteId && $schedule->site_id && ($biometricSiteId != $schedule->site_id);

            $attendance->actual_time_in = $actualTimeIn;
            $attendance->bio_in_site_id = $biometricSiteId;
            $attendance->status = $status;
            $attendance->tardy_minutes = ($tardyMinutes > 0 && $actualTimeIn->greaterThan($scheduledTimeIn)) ? $tardyMinutes : null;
            $attendance->is_cross_site_bio = $isCrossSite;
        }

        // Process time out
        if ($timeOutRecord) {
            $actualTimeOut = $timeOutRecord['datetime'];
            // Calculate time difference: positive = overtime (left late), negative = undertime (left early)
            $timeDiffMinutes = $scheduledTimeOut->diffInMinutes($actualTimeOut, false);

            $bioInSiteId = $attendance->bio_in_site_id;
            $isCrossSite = $attendance->is_cross_site_bio ||
                          ($biometricSiteId && $schedule->site_id && ($biometricSiteId != $schedule->site_id)) ||
                          ($biometricSiteId && $bioInSiteId && ($biometricSiteId != $bioInSiteId));

            $attendance->actual_time_out = $actualTimeOut;
            $attendance->bio_out_site_id = $biometricSiteId;
            $attendance->is_cross_site_bio = $isCrossSite;

            // Check for undertime (> 60 minutes early)
            if ($timeDiffMinutes < -60) {
                $attendance->undertime_minutes = abs($timeDiffMinutes);
                if ($attendance->status === 'on_time') {
                    $attendance->status = 'undertime';
                }
                // If primary status is tardy or half_day_absence, add undertime as secondary
                elseif (in_array($attendance->status, ['tardy', 'half_day_absence'])) {
                    $attendance->secondary_status = 'undertime';
                }
            } else {
                // Not enough undertime, clear it
                $attendance->undertime_minutes = null;
            }

            // Check for overtime (worked beyond scheduled time out)
            // Only count overtime if they worked more than 1 hour beyond scheduled time
            if ($timeDiffMinutes > 60) { // More than 1 hour late = overtime
                $attendance->overtime_minutes = $timeDiffMinutes;
            } else {
                // Not enough overtime, clear it
                $attendance->overtime_minutes = null;
            }
        } else {
            // No TIME OUT - ensure overtime/undertime are cleared
            $attendance->overtime_minutes = null;
            $attendance->undertime_minutes = null;
        }

        // Determine final status based on what biometric records were found
        // Priority: Handle missing bio scenarios
        if (!$timeInRecord && !$timeOutRecord) {
            // No bio at all
            $attendance->status = 'ncns';
            $attendance->secondary_status = null;
        } elseif (!$timeInRecord && $timeOutRecord) {
            // Has time OUT but missing time IN
            $attendance->status = 'failed_bio_in';
            $attendance->secondary_status = null;
        } elseif ($timeInRecord && !$timeOutRecord) {
            // Has time IN but missing time OUT
            // Keep the time-in status (tardy, half_day_absence) as primary
            // Add failed_bio_out as secondary status
            if ($attendance->status === 'on_time') {
                $attendance->status = 'failed_bio_out';
                $attendance->secondary_status = null;
            } else {
                // tardy, half_day_absence, or undertime - keep as primary, add missing bio out as secondary
                $attendance->secondary_status = 'failed_bio_out';
            }
        } else {
            // Both records exist - secondary status may have been set for undertime
            // Don't clear it if it was already set above
            if (!isset($attendance->secondary_status)) {
                $attendance->secondary_status = null;
            }
        }
        // If both exist, status was already set during time in processing

        // Detect extreme/suspicious scan patterns
        $scanAnalysis = $this->detectExtremeScanPatterns(
            $records,
            $schedule,
            $shiftDate,
            $timeInRecord,
            $timeOutRecord
        );

        // If extreme patterns detected, add warnings but keep original status
        // Only override status to 'needs_manual_review' if there's no clear status to assign
        if ($scanAnalysis['needs_review']) {
            $attendance->warnings = $scanAnalysis['warnings'];

            // Only set needs_manual_review if status is ambiguous (NCNS or failed bio)
            // Keep specific statuses like tardy, undertime, on_time, half_day_absence
            if (in_array($attendance->status, ['ncns', 'failed_bio_in', 'failed_bio_out'])) {
                $attendance->status = 'needs_manual_review';
            }

            \Log::warning('Attendance flagged for manual review', [
                'user_id' => $user->id,
                'shift_date' => $shiftDate->format('Y-m-d'),
                'original_status' => $attendance->status,
                'warnings' => $scanAnalysis['warnings'],
            ]);
        }

        // Save all changes
        $attendance->save();

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
        );

        // Skip updating if this is a verified record (manual entry, approved overtime, etc.)
        if ($attendance->admin_verified) {
            return [
                'matched' => true,
                'records_processed' => 0, // Skipped
                'errors' => [],
            ];
        }

        if ($timeInRecord) {
            // Calculate tardiness
            $actualTimeIn = $timeInRecord['datetime'];
            $tardyMinutes = $scheduledTimeIn->diffInMinutes($actualTimeIn, false);

            // Determine status based on rules and grace period
            $gracePeriodMinutes = $schedule->grace_period_minutes ?? 15;
            $status = $this->determineTimeInStatus($tardyMinutes, $gracePeriodMinutes);

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

        // Skip updating if this is a verified record (manual entry, approved overtime, etc.)
        if ($attendance->admin_verified) {
            return [
                'matched' => true,
                'records_processed' => 0, // Skipped
                'errors' => [],
            ];
        }

        // Build scheduled time out datetime (could be next day for night shift)
        $scheduledTimeOut = Carbon::parse($shiftDate->format('Y-m-d') . ' ' . $schedule->scheduled_time_out);

        // Determine expected date for time out (usually next day for night shifts)
        $expectedTimeOutDate = $shiftDate->copy();
        if ($schedule->isNightShift()) {
            $expectedTimeOutDate->addDay();
        }

        // Get the expected time out hour to help distinguish morning vs evening time outs
        $scheduledOutHour = Carbon::parse($schedule->scheduled_time_out)->hour;

        // Find time out record on expected date
        // Pass scheduled time out for smart matching when multiple records exist
        $timeOutRecord = $this->parser->findTimeOutRecord(
            $records,
            $expectedTimeOutDate,
            $scheduledOutHour,
            $schedule->scheduled_time_out
        );        if ($timeOutRecord) {
            $actualTimeOut = $timeOutRecord['datetime'];
            // Calculate time difference: positive = overtime (left late), negative = undertime (left early)
            $timeDiffMinutes = $scheduledTimeOut->diffInMinutes($actualTimeOut, false);

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
            if ($timeDiffMinutes < -60) { // Negative means left early
                $updates['undertime_minutes'] = abs($timeDiffMinutes);
                if ($attendance->status === 'on_time') {
                    $updates['status'] = 'undertime';
                }
            }

            // Check for overtime (worked beyond scheduled time out)
            // Only count overtime if they left after scheduled time out
            if ($timeDiffMinutes > 0) { // Positive means left late (overtime)
                $updates['overtime_minutes'] = $timeDiffMinutes;
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
     * Determine status based on tardiness minutes and grace period.
     *
     * @param int $tardyMinutes Positive if late, negative if early
     * @param int $gracePeriodMinutes Grace period from employee schedule (default 15)
     * @return string
     */
    protected function determineTimeInStatus(int $tardyMinutes, int $gracePeriodMinutes = 15): string
    {
        // More than grace period minutes late - half day absence (check this first)
        if ($tardyMinutes > $gracePeriodMinutes) {
            return 'half_day_absence';
        }

        // 15 minutes or more late, but within grace period - tardy
        if ($tardyMinutes >= 15) {
            return 'tardy';
        }

        // Less than 15 minutes late - considered on time
        return 'on_time';
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

    /**
     * Generate attendance points for violations from attendance records.
     * This is called automatically after processing an upload.
     *
     * @param Carbon $shiftDate
     * @return int Number of points created
     */
    protected function generateAttendancePoints(Carbon $shiftDate): int
    {
        \Log::info('Generating attendance points', [
            'shift_date' => $shiftDate->format('Y-m-d'),
        ]);

        // Get attendance records for this shift date that need points
        // ONLY process records that are admin verified
        $attendances = Attendance::where('shift_date', $shiftDate)
            ->whereIn('status', ['ncns', 'half_day_absence', 'tardy', 'undertime'])
            ->where('admin_verified', true)
            ->get();

        $pointsCreated = 0;
        $pointsToInsert = [];

        foreach ($attendances as $attendance) {
            // Check if point already exists for this attendance record
            $existingPoint = AttendancePoint::where('user_id', $attendance->user_id)
                ->where('shift_date', $attendance->shift_date)
                ->where('point_type', $this->mapStatusToPointType($attendance->status))
                ->first();

            if ($existingPoint) {
                // Point already exists, skip
                continue;
            }

            // Determine point type and value
            $pointType = $this->mapStatusToPointType($attendance->status);
            $pointValue = AttendancePoint::POINT_VALUES[$pointType] ?? 0;

            if ($pointValue > 0) {
                // Determine if NCNS/FTN (whole day absence without advice)
                $isNcnsOrFtn = $pointType === 'whole_day_absence' && !$attendance->is_advised;

                // Calculate expiration date (6 months for standard, 1 year for NCNS/FTN)
                $expiresAt = $isNcnsOrFtn
                    ? $shiftDate->copy()->addYear()
                    : $shiftDate->copy()->addMonths(6);

                // Generate violation details
                $violationDetails = $this->generateViolationDetails($attendance);

                $pointsToInsert[] = [
                    'user_id' => $attendance->user_id,
                    'attendance_id' => $attendance->id,
                    'shift_date' => $attendance->shift_date,
                    'point_type' => $pointType,
                    'points' => $pointValue,
                    'status' => $attendance->status,
                    'is_advised' => $attendance->is_advised ?? false,
                    'is_excused' => false,
                    'expires_at' => $expiresAt,
                    'expiration_type' => $isNcnsOrFtn ? 'none' : 'sro',
                    'is_expired' => false,
                    'violation_details' => $violationDetails,
                    'tardy_minutes' => $attendance->tardy_minutes,
                    'undertime_minutes' => $attendance->undertime_minutes,
                    'eligible_for_gbro' => !$isNcnsOrFtn, // NCNS/FTN not eligible for GBRO
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                $pointsCreated++;
            }
        }

        // Bulk insert all points at once for performance
        if (!empty($pointsToInsert)) {
            AttendancePoint::insert($pointsToInsert);
            \Log::info('Attendance points created', [
                'shift_date' => $shiftDate->format('Y-m-d'),
                'points_created' => $pointsCreated,
            ]);
        } else {
            \Log::info('No attendance points needed', [
                'shift_date' => $shiftDate->format('Y-m-d'),
            ]);
        }

        return $pointsCreated;
    }

    /**
     * Map attendance status to attendance point type.
     *
     * @param string $status
     * @return string
     */
    protected function mapStatusToPointType(string $status): string
    {
        return match ($status) {
            'ncns' => 'whole_day_absence',
            'advised_absence' => 'whole_day_absence',
            'half_day_absence' => 'half_day_absence',
            'tardy' => 'tardy',
            'undertime' => 'undertime',
            default => 'whole_day_absence',
        };
    }

    /**
     * Generate detailed violation description.
     *
     * @param Attendance $attendance
     * @return string
     */
    protected function generateViolationDetails(Attendance $attendance): string
    {
        $scheduledIn = $attendance->scheduled_time_in ? Carbon::parse($attendance->scheduled_time_in)->format('H:i') : 'N/A';
        $scheduledOut = $attendance->scheduled_time_out ? Carbon::parse($attendance->scheduled_time_out)->format('H:i') : 'N/A';
        $actualIn = $attendance->actual_time_in ? $attendance->actual_time_in->format('H:i') : 'No scan';
        $actualOut = $attendance->actual_time_out ? $attendance->actual_time_out->format('H:i') : 'No scan';

        // Get grace period from employee schedule
        $gracePeriod = $attendance->employeeSchedule?->grace_period_minutes ?? 15;

        return match ($attendance->status) {
            'ncns' => $attendance->is_advised
                ? "Failed to Notify (FTN): Employee did not report for work despite being advised. Scheduled: {$scheduledIn} - {$scheduledOut}. No biometric scans recorded."
                : "No Call, No Show (NCNS): Employee did not report for work and did not provide prior notice. Scheduled: {$scheduledIn} - {$scheduledOut}. No biometric scans recorded.",

            'half_day_absence' => sprintf(
                "Half-Day Absence: Arrived %d minutes late (more than %d minutes grace period). Scheduled: %s, Actual: %s.",
                $attendance->tardy_minutes ?? 0,
                $gracePeriod,
                $scheduledIn,
                $actualIn
            ),

            'tardy' => sprintf(
                "Tardy: Arrived %d minutes late. Scheduled time in: %s, Actual time in: %s.",
                $attendance->tardy_minutes ?? 0,
                $scheduledIn,
                $actualIn
            ),

            'undertime' => sprintf(
                "Undertime: Left %d minutes early (more than 1 hour before scheduled end). Scheduled: %s, Actual: %s.",
                $attendance->undertime_minutes ?? 0,
                $scheduledOut,
                $actualOut
            ),

            default => sprintf("Attendance violation on %s", Carbon::parse($attendance->shift_date)->format('Y-m-d')),
        };
    }

    /**
     * Regenerate attendance points for a single attendance record.
     * Used when attendance status is manually changed.
     *
     * @param Attendance $attendance
     * @return void
     */
    public function regeneratePointsForAttendance(Attendance $attendance): void
    {
        \Log::info('Regenerating attendance points for single record', [
            'attendance_id' => $attendance->id,
            'user_id' => $attendance->user_id,
            'status' => $attendance->status,
            'shift_date' => $attendance->shift_date,
        ]);

        // Only generate points for statuses that require them
        if (!in_array($attendance->status, ['ncns', 'half_day_absence', 'tardy', 'undertime', 'advised_absence'])) {
            \Log::info('Status does not require points', ['status' => $attendance->status]);
            return;
        }

        // Determine point type and value
        $pointType = $this->mapStatusToPointType($attendance->status);
        $pointValue = AttendancePoint::POINT_VALUES[$pointType] ?? 0;

        if ($pointValue <= 0) {
            \Log::info('No point value for this type', ['point_type' => $pointType]);
            return;
        }

        $shiftDate = Carbon::parse($attendance->shift_date);

        // Determine if NCNS (whole day absence without advice)
        // Advised Absence gets 6-month expiration and is eligible for GBRO
        $isNcns = $pointType === 'whole_day_absence' && !$attendance->is_advised;

        // Calculate expiration date:
        // - NCNS: 1 year expiration, not eligible for GBRO
        // - Advised Absence: 6 months expiration, eligible for GBRO
        // - All others: 6 months expiration, eligible for GBRO
        $expiresAt = $isNcns
            ? $shiftDate->copy()->addYear()
            : $shiftDate->copy()->addMonths(6);

        // Generate violation details
        $violationDetails = $this->generateViolationDetails($attendance);

        // Create the attendance point
        AttendancePoint::create([
            'user_id' => $attendance->user_id,
            'attendance_id' => $attendance->id,
            'shift_date' => $attendance->shift_date,
            'point_type' => $pointType,
            'points' => $pointValue,
            'status' => $attendance->status,
            'is_advised' => $attendance->is_advised ?? false,
            'is_excused' => false,
            'expires_at' => $expiresAt,
            'expiration_type' => $isNcns ? 'none' : 'sro',
            'is_expired' => false,
            'violation_details' => $violationDetails,
            'tardy_minutes' => $attendance->tardy_minutes,
            'undertime_minutes' => $attendance->undertime_minutes,
            'eligible_for_gbro' => !$isNcns, // Only NCNS is not eligible for GBRO, Advised Absence is eligible
        ]);

        \Log::info('Attendance point created', [
            'attendance_id' => $attendance->id,
            'point_type' => $pointType,
            'points' => $pointValue,
        ]);
    }

    /**
     * Detect and create NCNS records for employees who are scheduled to work
     * but have no biometric scans in the uploaded file.
     *
     * @param AttendanceUpload $upload
     * @param Collection $records All biometric records from the file
     * @return void
     */
    protected function detectAbsentEmployees(AttendanceUpload $upload, Collection $records): void
    {
        // Get all dates found in the biometric records
        $datesInFile = $records->pluck('datetime')
            ->map(fn($dt) => $dt->format('Y-m-d'))
            ->unique()
            ->values();

        if ($datesInFile->isEmpty()) {
            return;
        }

        // Get all employees who are in the biometric file
        $employeesWithScans = $records->pluck('name')
            ->map(fn($name) => $this->parser->normalizeName($name))
            ->unique()
            ->values();

        // Get all active employees with schedules
        $allActiveEmployees = User::whereHas('employeeSchedules', function ($query) {
            $query->where('is_active', true);
        })->get();

        $ncnsCreated = 0;

        foreach ($allActiveEmployees as $employee) {
            $normalizedName = strtolower(trim($employee->last_name));

            // Skip if this employee has scans in the file
            if ($employeesWithScans->contains($normalizedName)) {
                continue;
            }

            // Get employee's active schedule
            $schedule = $employee->employeeSchedules()->where('is_active', true)->first();
            if (!$schedule) {
                continue;
            }

            // Check each date in the file
            foreach ($datesInFile as $dateStr) {
                $date = Carbon::parse($dateStr);
                $dayName = $date->format('l');

                // Check if employee works on this day
                if (!$schedule->worksOnDay($dayName)) {
                    continue;
                }

                // Check if employee has approved leave for this date
                $approvedLeave = $this->checkApprovedLeave($employee, $date);
                if ($approvedLeave) {
                    // Create on_leave attendance record instead of NCNS
                    $this->createLeaveAttendance($employee, $schedule, $date, $approvedLeave);
                    continue;
                }

                // Check if attendance record already exists
                $existingAttendance = Attendance::where('user_id', $employee->id)
                    ->where('shift_date', $date)
                    ->first();

                if ($existingAttendance) {
                    continue;
                }

                // Create NCNS attendance record
                $scheduledTimeIn = Carbon::parse($date->format('Y-m-d') . ' ' . $schedule->scheduled_time_in);
                $scheduledTimeOut = Carbon::parse($date->format('Y-m-d') . ' ' . $schedule->scheduled_time_out);

                // Adjust time out for next-day shifts
                if ($this->isNextDayShift($schedule)) {
                    $scheduledTimeOut->addDay();
                }

                Attendance::create([
                    'user_id' => $employee->id,
                    'employee_schedule_id' => $schedule->id,
                    'shift_date' => $date,
                    'scheduled_time_in' => $scheduledTimeIn,
                    'scheduled_time_out' => $scheduledTimeOut,
                    'actual_time_in' => null,
                    'actual_time_out' => null,
                    'status' => 'ncns',
                    'is_advised' => false,
                    'admin_verified' => false,
                    'notes' => 'No biometric scans found for scheduled work day. Automatically marked as NCNS (No Call No Show).',
                ]);

                $ncnsCreated++;

                \Log::info('Created NCNS record for absent employee', [
                    'user_id' => $employee->id,
                    'user_name' => $employee->name,
                    'shift_date' => $date->format('Y-m-d'),
                    'day' => $dayName,
                ]);
            }
        }

        if ($ncnsCreated > 0) {
            \Log::warning('Absent employees detected', [
                'upload_id' => $upload->id,
                'ncns_created' => $ncnsCreated,
            ]);
        }
    }

    /**
     * Detect extreme/suspicious scan patterns that need manual review.
     *
     * @param Collection $records All biometric records for the employee
     * @param EmployeeSchedule $schedule The employee's schedule
     * @param Carbon $shiftDate The shift date
     * @param mixed $timeInRecord The detected time in record (or null)
     * @param mixed $timeOutRecord The detected time out record (or null)
     * @return array ['needs_review' => bool, 'warnings' => array]
     */
    protected function detectExtremeScanPatterns(
        Collection $records,
        EmployeeSchedule $schedule,
        Carbon $shiftDate,
        $timeInRecord,
        $timeOutRecord
    ): array {
        $warnings = [];
        $needsReview = false;

        // Only consider records on or near the shift date
        $relevantRecords = $records->filter(function ($record) use ($shiftDate) {
            $scanDate = Carbon::parse($record['datetime']->format('Y-m-d'));
            return $scanDate->equalTo($shiftDate) ||
                   $scanDate->equalTo($shiftDate->copy()->subDay()) ||
                   $scanDate->equalTo($shiftDate->copy()->addDay());
        });

        $scanCount = $relevantRecords->count();

        // Build full scheduled datetime for accurate comparison
        $scheduledTimeInFull = Carbon::parse($shiftDate->format('Y-m-d') . ' ' . $schedule->scheduled_time_in);
        $scheduledTimeOutFull = Carbon::parse($shiftDate->format('Y-m-d') . ' ' . $schedule->scheduled_time_out);

        // Adjust time out if it's a next-day shift
        $schedTimeIn = Carbon::parse($schedule->scheduled_time_in);
        $schedTimeOut = Carbon::parse($schedule->scheduled_time_out);
        if ($schedTimeOut->lessThanOrEqualTo($schedTimeIn)) {
            $scheduledTimeOutFull->addDay();
        }

        // Warning 1: Very few scans (only 1-2) far from scheduled times
        if ($scanCount <= 2) {
            $allScansExtreme = true;
            foreach ($relevantRecords as $record) {
                $scanTime = $record['datetime'];
                $hoursFromTimeIn = abs($scheduledTimeInFull->diffInHours($scanTime, false));
                $hoursFromTimeOut = abs($scheduledTimeOutFull->diffInHours($scanTime, false));

                // If any scan is within 2 hours of scheduled time, not all extreme
                if ($hoursFromTimeIn <= 2 || $hoursFromTimeOut <= 2) {
                    $allScansExtreme = false;
                    break;
                }
            }

            if ($allScansExtreme && $scanCount > 0) {
                $scanTimes = $relevantRecords->pluck('datetime')->map(fn($dt) => $dt->format('Y-m-d H:i'))->join(', ');
                $warnings[] = "Employee has only {$scanCount} biometric scan(s) on this date ({$scanTimes}), and the scan time(s) don't match the scheduled shift times. This may indicate: wrong shift assignment, scanner testing, or the employee worked a different shift.";
                $needsReview = true;
            }
        }

        // Warning 2: Time IN is extremely early (>3 hours before scheduled)
        if ($timeInRecord) {
            $actualTimeIn = $timeInRecord['datetime'];
            $minutesBeforeScheduled = $scheduledTimeInFull->diffInMinutes($actualTimeIn, false);

            if ($minutesBeforeScheduled < -180) { // More than 3 hours early
                $hours = abs(round($minutesBeforeScheduled / 60, 1));
                $warnings[] = "Time IN is {$hours} hours before scheduled time ({$actualTimeIn->format('Y-m-d H:i')} vs {$scheduledTimeInFull->format('Y-m-d H:i')})";
                $needsReview = true;
            }
        }

        // Warning 3: Time OUT is extremely late (>4 hours after scheduled)
        if ($timeOutRecord) {
            $actualTimeOut = $timeOutRecord['datetime'];
            $minutesAfterScheduled = $actualTimeOut->diffInMinutes($scheduledTimeOutFull, false);

            if ($minutesAfterScheduled < -240) { // More than 4 hours late (beyond overtime threshold)
                $hours = abs(round($minutesAfterScheduled / 60, 1));
                $warnings[] = "Time OUT is {$hours} hours after scheduled time ({$actualTimeOut->format('Y-m-d H:i')} vs {$scheduledTimeOutFull->format('Y-m-d H:i')})";
                $needsReview = true;
            }

            // Warning 3b: Time OUT is extremely early (>3 hours before scheduled)
            if ($minutesAfterScheduled > 180) { // More than 3 hours early
                $hours = abs(round($minutesAfterScheduled / 60, 1));
                $warnings[] = "Time OUT is {$hours} hours before scheduled time ({$actualTimeOut->format('Y-m-d H:i')} vs {$scheduledTimeOutFull->format('Y-m-d H:i')}). This may indicate: emergency, medical issue, or unauthorized early departure.";
                $needsReview = true;
            }
        }

        // Warning 4: Large time gap between only two scans (>12 hours)
        if ($scanCount == 2 && !$timeInRecord && !$timeOutRecord) {
            $firstScan = $relevantRecords->first()['datetime'];
            $lastScan = $relevantRecords->last()['datetime'];
            $hoursBetween = $firstScan->diffInHours($lastScan);

            if ($hoursBetween > 12) {
                $warnings[] = "Only 2 scans found with {$hoursBetween} hours gap ({$firstScan->format('H:i')} and {$lastScan->format('H:i')}), neither matches schedule";
                $needsReview = true;
            }
        }

        // Warning 5: No valid time IN or OUT detected despite having scans
        if ($scanCount > 0 && !$timeInRecord && !$timeOutRecord) {
            $scanTimes = $relevantRecords->pluck('datetime')->map(fn($dt) => $dt->format('H:i'))->join(', ');
            $warnings[] = "No valid time IN/OUT detected from {$scanCount} scan(s) at: {$scanTimes}";
            $needsReview = true;
        }

        return [
            'needs_review' => $needsReview,
            'warnings' => $warnings,
        ];
    }
}
