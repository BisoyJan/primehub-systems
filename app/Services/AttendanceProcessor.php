<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\AttendancePoint;
use App\Models\AttendanceUpload;
use App\Models\BiometricRecord;
use App\Models\EmployeeSchedule;
use App\Models\LeaveRequest;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AttendanceProcessor
{
    protected AttendanceFileParser $parser;

    protected ?LeaveCreditService $leaveCreditService = null;

    protected ?NotificationService $notificationService = null;

    /**
     * Cached users collection for performance optimization.
     * Loaded once per processor instance to avoid repeated database queries.
     */
    protected ?Collection $cachedUsers = null;

    /**
     * Pre-built lookup indexes for fast name matching.
     * Keys are normalized name patterns, values are user IDs or arrays of user IDs.
     */
    protected array $userLookupIndex = [];

    /**
     * Index of users grouped by last name for disambiguation.
     */
    protected array $usersByLastName = [];

    public function __construct(AttendanceFileParser $parser)
    {
        $this->parser = $parser;
        $this->leaveCreditService = app(LeaveCreditService::class);
        $this->notificationService = app(NotificationService::class);
    }

    /**
     * Initialize user cache and build lookup indexes for fast name matching.
     * Should be called once before processing multiple records.
     */
    protected function initializeUserCache(): void
    {
        if ($this->cachedUsers !== null) {
            return; // Already initialized
        }

        $this->cachedUsers = User::all();
        $this->userLookupIndex = [];
        $this->usersByLastName = [];

        foreach ($this->cachedUsers as $user) {
            $lastName = strtolower(trim($user->last_name));
            $firstName = strtolower(trim($user->first_name));
            $middleName = strtolower(trim($user->middle_name ?? ''));
            $firstInitial = substr($firstName, 0, 1);
            $firstTwoLetters = substr($firstName, 0, 2);

            // Group users by last name for disambiguation
            if (! isset($this->usersByLastName[$lastName])) {
                $this->usersByLastName[$lastName] = [];
            }
            $this->usersByLastName[$lastName][] = $user;

            // Build lookup patterns (more specific patterns first for priority)

            // Pattern 2: Last name + first 2 letters (most specific with initial)
            $this->addToIndex($lastName.' '.$firstTwoLetters, $user);

            // Pattern 4: Full name "Last First"
            $this->addToIndex($lastName.' '.$firstName, $user);

            // Pattern 5: Full name "First Last"
            $this->addToIndex($firstName.' '.$lastName, $user);

            // Patterns with middle name
            if (! empty($middleName)) {
                $middleInitial = substr($middleName, 0, 1);

                // Pattern 6: "Last First Middle"
                $this->addToIndex($lastName.' '.$firstName.' '.$middleName, $user);

                // Pattern 7: "First Middle Last"
                $this->addToIndex($firstName.' '.$middleName.' '.$lastName, $user);

                // Pattern 9: "First M Last"
                $this->addToIndex($firstName.' '.$middleInitial.' '.$lastName, $user);

                // Pattern 10: "Last First M"
                $this->addToIndex($lastName.' '.$firstName.' '.$middleInitial, $user);
            }

            // Handle compound first names
            if (str_contains($firstName, ' ')) {
                $firstWord = explode(' ', $firstName)[0];

                // Pattern 12: "Last FirstWord"
                $this->addToIndex($lastName.' '.$firstWord, $user);

                // Pattern 13: "FirstWord Last"
                $this->addToIndex($firstWord.' '.$lastName, $user);
            }

            // Pattern 3: Last name + first initial (may have multiple matches)
            $this->addToIndex($lastName.' '.$firstInitial, $user);

            // Pattern 1: Just last name (may have multiple matches)
            $this->addToIndex($lastName, $user);
        }
    }

    /**
     * Add a user to the lookup index for a given pattern.
     */
    protected function addToIndex(string $pattern, User $user): void
    {
        if (! isset($this->userLookupIndex[$pattern])) {
            $this->userLookupIndex[$pattern] = [];
        }
        $this->userLookupIndex[$pattern][] = $user;
    }

    /**
     * Clear the user cache. Call this if users are modified during processing.
     */
    public function clearUserCache(): void
    {
        $this->cachedUsers = null;
        $this->userLookupIndex = [];
        $this->usersByLastName = [];
    }

    /**
     * Process uploaded attendance file and create attendance records.
     *
     * This method performs the following operations:
     * 1. Updates upload status to 'processing'
     * 2. Parses the biometric file and groups records by employee
     * 3. Saves all raw biometric records to the database
     * 4. Validates that file dates match the expected shift date
     * 5. Processes each employee's records and creates attendance entries
     * 6. Tracks matched/unmatched employees and non-work day scans
     * 7. Updates the upload record with processing statistics
     * 8. Detects absent employees and creates NCNS records
     * 9. Commits transaction on success or rolls back on failure
     *
     * Note: Attendance points are NOT auto-generated during this process.
     * Points should be generated only after admin verification via the review page.
     *
     * @param  AttendanceUpload  $upload  The attendance upload record being processed
     * @param  string  $filePath  The absolute path to the uploaded biometric file
     * @return array Processing results containing:
     *               - total_records: Total number of biometric records parsed
     *               - processed: Number of records successfully processed
     *               - matched_employees: Count of employees matched to database
     *               - unmatched_names: Array of employee names not found in system
     *               - errors: Array of error messages encountered during processing
     *               - date_warnings: Array of warnings about date mismatches
     *               - dates_found: Array of unique dates found in the file
     *               - non_work_day_scans: Array of scans detected on non-scheduled days
     *
     * @throws \Exception If processing fails, transaction is rolled back and exception is re-thrown
     *
     * @see AttendanceParser::parse() For file parsing logic
     * @see processEmployeeRecords() For individual employee processing
     * @see detectAbsentEmployees() For NCNS detection
     */
    /**
     * Process uploaded attendance file.
     *
     * @param  bool  $filterByDate  Whether to filter records by date range (default: true)
     * @return array Processing results
     */
    public function processUpload(AttendanceUpload $upload, string $filePath, bool $filterByDate = true): array
    {
        try {
            DB::beginTransaction();

            $upload->update(['status' => 'processing']);

            // Parse the file
            $allRecords = $this->parser->parse($filePath);

            // Filter records by date range if enabled
            $records = $allRecords;
            $skippedRecords = collect();
            $filterSummary = null;

            if ($filterByDate && $upload->date_from && $upload->date_to) {
                $dateFrom = Carbon::parse($upload->date_from);
                $dateTo = Carbon::parse($upload->date_to);
                $filterResult = $this->parser->filterByDateRange($allRecords, $dateFrom, $dateTo);

                $records = $filterResult['within_range'];
                $skippedRecords = $filterResult['outside_range'];
                $filterSummary = $filterResult['summary'];

                \Log::info('Date range filtering applied', [
                    'date_from' => $dateFrom->format('Y-m-d'),
                    'date_to' => $dateTo->format('Y-m-d'),
                    'total_records' => $allRecords->count(),
                    'within_range' => $records->count(),
                    'outside_range' => $skippedRecords->count(),
                ]);
            }

            $groupedRecords = $this->parser->groupByEmployee($records);

            \Log::info('Attendance processing started', [
                'total_records' => $allRecords->count(),
                'filtered_records' => $records->count(),
                'unique_employees' => $groupedRecords->count(),
            ]);

            // Save raw biometric records to database
            // Only save records within date range when filtering is enabled
            $recordsToSave = $filterByDate ? $records : $allRecords;
            $this->saveBiometricRecords($recordsToSave, $upload);

            // Validate file dates match expected dates for the shift
            $dateValidation = $this->validateFileDates($records, Carbon::parse($upload->shift_date));

            $stats = [
                'total_records' => $allRecords->count(),
                'filtered_records' => $records->count(),
                'skipped_records' => $skippedRecords->count(),
                'filter_applied' => $filterByDate,
                'filter_summary' => $filterSummary,
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
                    if (! empty($result['skipped_non_work_days'])) {
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
                        'original_name' => $employeeRecords->first()['name'],
                    ]);
                }

                if (! empty($result['errors'])) {
                    $stats['errors'] = array_merge($stats['errors'], $result['errors']);
                }
            }

            // Log summary of non-work day scans for admin review
            if (! empty($stats['non_work_day_scans'])) {
                \Log::warning('Biometric scans detected on non-scheduled work days', [
                    'upload_id' => $upload->id,
                    'count' => count($stats['non_work_day_scans']),
                    'details' => $stats['non_work_day_scans'],
                ]);
            }

            // Sanitize unmatched names to ensure valid UTF-8 for JSON encoding
            $sanitizedUnmatchedNames = array_map(function ($name) {
                // Convert to UTF-8 if not already
                if (! mb_check_encoding($name, 'UTF-8')) {
                    $name = mb_convert_encoding($name, 'UTF-8', 'Windows-1252');
                }
                // Remove any remaining invalid UTF-8 sequences
                $name = mb_convert_encoding($name, 'UTF-8', 'UTF-8');

                // Final cleanup: remove any characters that can't be JSON encoded
                return preg_replace('/[^\x20-\x7E\xA0-\xFF\x{0100}-\x{FFFF}]/u', '', $name) ?: $name;
            }, $stats['unmatched_names']);

            // Update upload record
            $upload->update([
                'status' => 'completed',
                'total_records' => $stats['total_records'],
                'processed_records' => $stats['processed'],
                'matched_employees' => $stats['matched_employees'],
                'unmatched_names' => count($sanitizedUnmatchedNames),
                'unmatched_names_list' => $sanitizedUnmatchedNames,
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
     */
    protected function saveBiometricRecords(Collection $records, AttendanceUpload $upload): void
    {
        $biometricRecords = [];

        foreach ($records as $record) {
            // Find user by name
            $normalizedName = $this->parser->normalizeName($record['name']);
            $user = $this->findUserByName($normalizedName, collect([$record]));

            if (! $user) {
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
        if (! empty($biometricRecords)) {
            BiometricRecord::insert($biometricRecords);
            \Log::info('Saved biometric records', [
                'count' => count($biometricRecords),
                'upload_id' => $upload->id,
            ]);
        }
    }

    /**
     * Validate that file dates match expected dates for the shift.
     */
    protected function validateFileDates(Collection $records, Carbon $shiftDate): array
    {
        $warnings = [];
        $datesFound = [];

        // Collect all unique dates from records
        foreach ($records as $record) {
            $recordDate = $record['datetime']->format('Y-m-d');
            if (! in_array($recordDate, $datesFound)) {
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

        if (! empty($unexpectedDates)) {
            $warnings[] = sprintf(
                'File contains records from unexpected dates: %s. Expected dates: %s for shift date %s.',
                implode(', ', array_map(fn ($d) => Carbon::parse($d)->format('M d, Y'), $unexpectedDates)),
                implode(', ', array_map(fn ($d) => Carbon::parse($d)->format('M d, Y'), $expectedDates)),
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
     * @param  Carbon  $shiftDate  (now used as reference date for the file, not the actual shift date)
     */
    protected function processEmployeeRecords(
        string $normalizedName,
        Collection $records,
        Carbon $shiftDate,
        ?int $biometricSiteId = null
    ): array {
        // Find user by matching name
        $user = $this->findUserByName($normalizedName, $records);

        if (! $user) {
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
        if (! empty($skippedNonWorkDays)) {
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
     */
    public function reprocessEmployeeRecords(string $normalizedName, Collection $records, Carbon $shiftDate): array
    {
        return $this->processEmployeeRecords($normalizedName, $records, $shiftDate);
    }

    /**
     * Determine shift type based on employee's schedule.
     *
     * @param  EmployeeSchedule|null  $schedule
     * @return string 'morning', 'afternoon', 'evening', 'night', or 'graveyard'
     */
    protected function determineShiftType($schedule): string
    {
        if (! $schedule) {
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
     * @param  EmployeeSchedule  $schedule
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
     */
    protected function groupRecordsByShiftDate(Collection $records, User $user): array
    {
        $groups = [];

        // Get user's active schedule to determine shift pattern
        $schedule = $user->employeeSchedules()->where('is_active', true)->first();

        if (! $schedule) {
            // No schedule, group by actual date
            foreach ($records as $record) {
                $shiftDate = $record['datetime']->format('Y-m-d');
                if (! isset($groups[$shiftDate])) {
                    $groups[$shiftDate] = collect();
                }
                $groups[$shiftDate]->push($record);
            }

            return $groups;
        }

        $isNextDayShift = $this->isNextDayShift($schedule);
        $scheduledHour = Carbon::parse($schedule->scheduled_time_in)->hour;
        $scheduledOutHour = Carbon::parse($schedule->scheduled_time_out)->hour;

        // Check if this is a graveyard shift (00:00-04:59 start time)
        // Graveyard shifts that start at midnight are treated as "previous day" shifts
        // because the employee's work day is considered to be the day BEFORE they clock in
        // Example: Mon-Fri schedule with 00:00-09:00 shift means:
        // - Friday's shift: Clock in Sat 00:00, clock out Sat 09:00 (shift_date = Friday)
        // - Thursday's shift: Clock in Fri 00:00, clock out Fri 09:00 (shift_date = Thursday)
        $isGraveyardShift = $scheduledHour >= 0 && $scheduledHour < 5;

        // Sort records by datetime to process chronologically
        $sortedRecords = $records->sortBy(function ($record) {
            return $record['datetime']->timestamp;
        });

        foreach ($sortedRecords as $record) {
            $datetime = $record['datetime'];
            $hour = $datetime->hour;

            if (! $isNextDayShift) {
                // SAME DAY SHIFT: All records go to their actual date
                // Examples: 08:00-17:00 (morning), 14:00-20:00 (afternoon)
                $shiftDate = $datetime->format('Y-m-d');

                // Special handling for graveyard shifts (00:00-04:59 start time):
                // Graveyard shifts are considered to belong to the PREVIOUS day
                // because the work day is the day before the clock-in date.
                // Example: For Mon-Fri schedule with 00:00-09:00 shift:
                // - Clocking in Friday 00:30 = Thursday's shift (shift_date = Thursday)
                // - Clocking in Saturday 00:30 = Friday's shift (shift_date = Friday)
                if ($isGraveyardShift) {
                    // For graveyard shifts, distinguish between:
                    // 1. Late evening records (hour >= 20): These are early TIME IN arrivals
                    //    for the CURRENT day's graveyard shift → keep on current date
                    // 2. Morning/daytime records: These are TIME OUT from the PREVIOUS day's shift
                    //    → assign to previous work day
                    //
                    // Example for 00:30-09:30 shift on Monday:
                    // - Mon 23:15 → early TIME IN for Monday's shift → shift_date = Monday
                    // - Tue 09:02 → TIME OUT for Monday's shift → shift_date = Monday
                    // - Tue 23:01 → early TIME IN for Tuesday's shift → shift_date = Tuesday
                    $isLateEveningRecord = $hour >= 20;

                    if ($isLateEveningRecord) {
                        // Early arrival for today's graveyard shift - keep on current date
                        $shiftDate = $datetime->format('Y-m-d');
                    } else {
                        $previousDay = $datetime->copy()->subDay();
                        $previousDayName = $previousDay->format('l');

                        // Only assign to previous day if the previous day is a work day
                        // This ensures we don't create shifts for days the employee doesn't work
                        if ($schedule->worksOnDay($previousDayName)) {
                            $shiftDate = $previousDay->format('Y-m-d');
                        }
                    }
                }
            } else {
                // NEXT DAY SHIFT: Determine which shift date based on time
                // Examples: 22:00-07:00 (night), 15:00-00:00 (afternoon extending to midnight)

                // Add tolerance: scans within 1 hour before shift start are considered part of that shift
                // This handles early scans like 21:55 for a 22:00 shift
                $scheduledTime = Carbon::parse($datetime->format('Y-m-d').' '.$schedule->scheduled_time_in);
                $minutesBeforeShift = $datetime->diffInMinutes($scheduledTime, false);

                // If scan is within 60 minutes before scheduled time, it belongs to TODAY's shift
                if ($minutesBeforeShift >= 0 && $minutesBeforeShift <= 60) {
                    $shiftDate = $datetime->format('Y-m-d');
                }
                // SPECIAL CASE: For late-night shifts starting at 22:00-23:59,
                // evening scans (18:00-23:59) should be treated as early TIME IN for TODAY's shift,
                // not as time out from yesterday's shift.
                // Example: For 23:00-08:00 shift, a 21:46 scan is an early arrival (came ~1hr early)
                elseif ($scheduledHour >= 22 && $hour >= 18 && $hour <= 23) {
                    // This is an early arrival for today's late-night shift
                    $shiftDate = $datetime->format('Y-m-d');
                }
                // If scan is clearly before shift time (morning/afternoon hours), it's from YESTERDAY's shift
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

            if (! isset($groups[$shiftDate])) {
                $groups[$shiftDate] = collect();
            }
            $groups[$shiftDate]->push($record);
        }

        return $groups;
    }

    /**
     * Process a single shift for an employee.
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

        if (! $schedule) {
            // Even without a schedule, create attendance from biometric data
            $this->createAttendanceWithoutSchedule($user, $records, $detectedShiftDate, $biometricSiteId);

            return [
                'processed' => true,
                'no_schedule' => true,
                'error' => null,
            ];
        }

        // Check if employee works on this day
        $dayName = $detectedShiftDate->format('l'); // Monday, Tuesday, etc.
        if (! $schedule->worksOnDay($dayName)) {
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
            // Check if biometric records indicate activity during leave
            // Instead of auto-cancelling, flag for HR/Admin review
            // This handles edge cases like:
            // - Accidental clock-ins (habit)
            // - Partial work days (came in 2 hours, went home sick)
            // - Multi-day leaves where employee only worked one day
            if ($this->hasSufficientWorkScans($records)) {
                // Flag for HR/Admin review - do NOT auto-cancel
                $flagResult = $this->flagLeaveForReview($approvedLeave, $user, $detectedShiftDate, $records);

                // Process attendance normally so we have the record
                // HR/Admin can review and decide to cancel leave if appropriate
                $result = $this->processAttendance($user, $schedule, $records, $detectedShiftDate, $biometricSiteId);

                // Mark attendance as needing review due to leave conflict
                $attendance = Attendance::where('user_id', $user->id)
                    ->where('shift_date', $detectedShiftDate)
                    ->first();

                if ($attendance) {
                    $attendance->update([
                        'admin_verified' => false, // Requires HR approval due to leave conflict
                        'leave_request_id' => $approvedLeave->id,
                        'remarks' => ($attendance->remarks ? $attendance->remarks.' | ' : '').
                            'Leave conflict: Employee on approved leave but has biometric activity. '.
                            "Duration: {$flagResult['work_duration']} hrs. Pending HR review.",
                    ]);
                }

                Log::info('Leave attendance conflict detected - flagged for HR review', [
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'leave_request_id' => $approvedLeave->id,
                    'shift_date' => $detectedShiftDate->format('Y-m-d'),
                    'scan_count' => $records->count(),
                    'work_duration' => $flagResult['work_duration'] ?? 0,
                ]);

                return [
                    'processed' => $result['matched'] ?? false,
                    'leave_conflict_flagged' => true,
                    'work_duration' => $flagResult['work_duration'] ?? 0,
                    'error' => ! empty($result['errors']) ? implode(', ', $result['errors']) : null,
                ];
            }

            // No work scans detected, create attendance record with on_leave status
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
            'error' => ! empty($result['errors']) ? implode(', ', $result['errors']) : null,
        ];
    }

    /**
     * Check if biometric records indicate sufficient work scans (employee actually worked).
     * Requires at least 2 scans (time in and time out) to consider as work.
     */
    protected function hasSufficientWorkScans(Collection $records): bool
    {
        // Require at least 2 scans (time in and time out) to be considered as working
        return $records->count() >= 2;
    }

    /**
     * Calculate estimated work duration from biometric records.
     * Returns duration in hours between first and last scan.
     *
     * @return float Hours worked (0 if less than 2 scans)
     */
    protected function calculateWorkDuration(Collection $records): float
    {
        if ($records->count() < 2) {
            return 0;
        }

        $sortedRecords = $records->sortBy(fn ($r) => $r['datetime']->timestamp);
        $firstScan = $sortedRecords->first()['datetime'];
        $lastScan = $sortedRecords->last()['datetime'];

        return round($firstScan->diffInMinutes($lastScan) / 60, 2);
    }

    /**
     * Flag leave request for HR/Admin review when employee has biometric activity during leave.
     * Does NOT auto-cancel - instead notifies HR/Admin to investigate and decide.
     *
     * This handles edge cases like:
     * - Accidental clock-ins (employee clocked in out of habit but didn't work)
     * - Partial work days (employee came in for 2 hours then went home sick)
     * - Multi-day leaves where only one day has attendance
     */
    protected function flagLeaveForReview(
        LeaveRequest $leaveRequest,
        User $user,
        Carbon $workDate,
        Collection $biometricRecords
    ): array {
        $scanTimes = $biometricRecords->pluck('datetime')
            ->map(fn ($dt) => $dt->format('H:i'))
            ->implode(', ');

        $workDuration = $this->calculateWorkDuration($biometricRecords);
        $scanCount = $biometricRecords->count();

        // Determine if this is a multi-day leave
        $startDate = Carbon::parse($leaveRequest->start_date);
        $endDate = Carbon::parse($leaveRequest->end_date);
        $isMultiDayLeave = $startDate->diffInDays($endDate) > 0;

        $details = "Employee {$user->name} has biometric activity during approved leave.\n".
                   "Leave: {$startDate->format('M d, Y')} to {$endDate->format('M d, Y')}\n".
                   "Activity Date: {$workDate->format('M d, Y')}\n".
                   "Scan Count: {$scanCount}\n".
                   "Scan Times: {$scanTimes}\n".
                   "Estimated Duration: {$workDuration} hours";

        try {
            // Notify HR/Admin for review (do NOT auto-cancel)
            if ($this->notificationService) {
                $this->notificationService->notifyLeaveAttendanceConflict(
                    $user,
                    $leaveRequest,
                    $workDate,
                    $scanCount,
                    $scanTimes,
                    $workDuration,
                    $isMultiDayLeave
                );
            }

            Log::info('Leave attendance conflict flagged for HR review', [
                'user_id' => $user->id,
                'user_name' => $user->name,
                'leave_request_id' => $leaveRequest->id,
                'work_date' => $workDate->format('Y-m-d'),
                'scan_count' => $scanCount,
                'work_duration_hours' => $workDuration,
                'is_multi_day_leave' => $isMultiDayLeave,
            ]);

            return [
                'flagged' => true,
                'details' => $details,
                'work_duration' => $workDuration,
                'is_multi_day_leave' => $isMultiDayLeave,
            ];
        } catch (\Exception $e) {
            Log::error('Failed to flag leave attendance conflict', [
                'leave_request_id' => $leaveRequest->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return ['flagged' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Create attendance record for employee on approved leave.
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
                'notes' => "On approved {$leaveRequest->leave_type}".($leaveRequest->reason ? " - {$leaveRequest->reason}" : ''),
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
                'notes' => "On approved {$leaveRequest->leave_type}".($leaveRequest->reason ? " - {$leaveRequest->reason}" : ''),
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
        $sortedRecords = $records->sortBy(fn ($r) => $r['datetime']->timestamp);
        $timeInRecord = $sortedRecords->first();
        $timeOutRecord = $sortedRecords->count() > 1 ? $sortedRecords->last() : null;

        // Build scheduled times (for reference, even though not a work day)
        $scheduledTimeIn = Carbon::parse($shiftDate->format('Y-m-d').' '.$schedule->scheduled_time_in);
        $scheduledTimeOut = Carbon::parse($shiftDate->format('Y-m-d').' '.$schedule->scheduled_time_out);

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
        $attendance->notes = "Biometric scans detected on non-scheduled work day ({$dayName}). ".
                           "Employee has {$records->count()} scan(s) on this date. ".
                           'This may represent overtime, special work, or data issue. Requires verification.';

        // Calculate total minutes worked (deduct 60 min lunch if worked > 5 hours)
        if ($attendance->actual_time_in && $attendance->actual_time_out) {
            $rawMinutes = $attendance->actual_time_in->diffInMinutes($attendance->actual_time_out);
            $lunchDeduction = ($rawMinutes / 60) > 5 ? 60 : 0;
            $attendance->total_minutes_worked = $rawMinutes - $lunchDeduction;
        } else {
            $attendance->total_minutes_worked = null;
        }

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
     * Create attendance record for user without a schedule.
     * This handles cases where biometric data exists but no schedule is assigned.
     */
    protected function createAttendanceWithoutSchedule(
        User $user,
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
        $sortedRecords = $records->sortBy(fn ($r) => $r['datetime']->timestamp);
        $timeInRecord = $sortedRecords->first();
        $timeOutRecord = $sortedRecords->count() > 1 ? $sortedRecords->last() : null;

        // Set values (no scheduled times since there's no schedule)
        $attendance->actual_time_in = $timeInRecord ? $timeInRecord['datetime'] : null;
        $attendance->actual_time_out = $timeOutRecord ? $timeOutRecord['datetime'] : null;
        $attendance->bio_in_site_id = $biometricSiteId;
        $attendance->bio_out_site_id = $biometricSiteId;
        $attendance->status = 'needs_manual_review'; // Use valid status for unscheduled attendance
        $attendance->admin_verified = false;
        $attendance->notes = 'No schedule found for this employee. Created from biometric data. '.
                           "Employee has {$records->count()} scan(s) on this date. Requires verification.";

        // Calculate total minutes worked (deduct 60 min lunch if worked > 5 hours)
        if ($attendance->actual_time_in && $attendance->actual_time_out) {
            $rawMinutes = $attendance->actual_time_in->diffInMinutes($attendance->actual_time_out);
            $lunchDeduction = ($rawMinutes / 60) > 5 ? 60 : 0;
            $attendance->total_minutes_worked = $rawMinutes - $lunchDeduction;
        } else {
            $attendance->total_minutes_worked = null;
        }

        $attendance->save();

        \Log::info('Created attendance record without schedule', [
            'user_id' => $user->id,
            'user_name' => $user->name,
            'shift_date' => $shiftDate->format('Y-m-d'),
            'scan_count' => $records->count(),
        ]);
    }

    /**
     * Process attendance for both time in and time out.
     *
     * @param  Carbon  $shiftDate  (the detected shift date from time in record)
     */
    protected function processAttendance(
        User $user,
        EmployeeSchedule $schedule,
        Collection $records,
        Carbon $shiftDate,
        ?int $biometricSiteId = null
    ): array {
        // Build scheduled time in datetime
        $scheduledTimeIn = Carbon::parse($shiftDate->format('Y-m-d').' '.$schedule->scheduled_time_in);

        // Time in is always on the shift date itself
        // E.g., Nov 5 shift -> time in on Nov 5
        $expectedTimeInDate = $shiftDate->copy();

        // Determine if time out is next day using the SAME logic as isNextDayShift()
        $schedTimeIn = Carbon::parse($schedule->scheduled_time_in);
        $schedTimeOut = Carbon::parse($schedule->scheduled_time_out);

        // Check for graveyard shift pattern (00:00-04:59 start time)
        $schedInHour = $schedTimeIn->hour;
        $schedOutHour = $schedTimeOut->hour;
        $isNextDayTimeOut = false;
        $isGraveyardShift = false;

        if ($schedInHour >= 0 && $schedInHour < 5 && $schedOutHour > $schedInHour) {
            // Graveyard shift (00:00-04:59 start time) that ends later in morning
            // Example: 00:00-09:00, 01:00-10:00
            // For graveyard shifts, the shift_date is the PREVIOUS day (work day),
            // but the actual biometric records are on the NEXT calendar day.
            // So we need to adjust expected dates to the next day.
            $isGraveyardShift = true;
            $isNextDayTimeOut = false; // Both in/out on same calendar day (next day from shift_date)

            // Adjust expected dates to next day (where the actual scans are)
            $expectedTimeInDate = $shiftDate->copy()->addDay();
            $scheduledTimeIn = Carbon::parse($expectedTimeInDate->format('Y-m-d').' '.$schedule->scheduled_time_in);
        } else {
            // Standard check: time out <= time in means next day
            // Example: 22:00-07:00 (time out 07:00 < time in 22:00, so next day)
            $isNextDayTimeOut = $schedTimeOut->lessThanOrEqualTo($schedTimeIn);
        }

        // Time out is next day if scheduled time out <= scheduled time in
        $expectedTimeOutDate = $shiftDate->copy();
        if ($isGraveyardShift) {
            // For graveyard shifts, both time in and out are on the next calendar day
            $expectedTimeOutDate = $shiftDate->copy()->addDay();
        } elseif ($isNextDayTimeOut) {
            $expectedTimeOutDate->addDay();
        }

        // Build scheduled time out datetime
        $scheduledTimeOut = Carbon::parse($shiftDate->format('Y-m-d').' '.$schedule->scheduled_time_out);
        if ($isGraveyardShift) {
            // For graveyard shifts, scheduled time out is on the next calendar day
            $scheduledTimeOut = Carbon::parse($expectedTimeOutDate->format('Y-m-d').' '.$schedule->scheduled_time_out);
        } elseif ($isNextDayTimeOut) {
            $scheduledTimeOut->addDay();
        }

        // Determine time in search range based on scheduled time
        $scheduledHour = Carbon::parse($schedule->scheduled_time_in)->hour;

        // Find time in record based on shift pattern
        if ($isGraveyardShift) {
            // GRAVEYARD SHIFT (00:00-04:59 start time)
            // TIME IN could be:
            // A) Late evening on shift date (early arrival before midnight)
            //    Example: 23:15 on Mon for a Mon 00:30-09:30 shift
            // B) Early morning on next calendar day (on-time or late arrival)
            //    Example: 00:35 on Tue for a Mon 00:30-09:30 shift

            // (A) Check for early arrivals on the shift date evening
            $timeInRecord = $this->parser->findTimeInRecordByTimeRange($records, $shiftDate, 20, 23);

            // (B) If no early arrival, look on the next calendar day
            // Use a restricted hour range (midnight to shift midpoint) to avoid picking up TIME OUT records
            if (! $timeInRecord) {
                $schedInMinutes = Carbon::parse($schedule->scheduled_time_in)->hour * 60 + Carbon::parse($schedule->scheduled_time_in)->minute;
                $schedOutMinutes = Carbon::parse($schedule->scheduled_time_out)->hour * 60 + Carbon::parse($schedule->scheduled_time_out)->minute;
                $midpointHour = (int) (($schedInMinutes + $schedOutMinutes) / 2 / 60);
                $maxSearchHour = max($midpointHour, $schedInHour + 3);

                $timeInRecord = $this->parser->findTimeInRecordByTimeRange(
                    $records, $expectedTimeInDate, 0, $maxSearchHour
                );
            }
        } elseif (! $isNextDayTimeOut) {
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

        // For graveyard shifts, use standard time out finding on the expected date (next calendar day)
        if ($isGraveyardShift) {
            // Graveyard shift: Time out is on the next calendar day from shift_date
            // Use standard matching to find the closest record to scheduled time out
            $timeOutRecord = $this->parser->findTimeOutRecord(
                $records,
                $expectedTimeOutDate,
                $scheduledOutHour,
                $schedule->scheduled_time_out
            );
        } elseif ($isNextDayTimeOut && $scheduledHour >= 0 && $scheduledHour < 5) {
            // Next-day graveyard shift (shouldn't happen with current logic, but kept for safety)
            // Time out is 00:00 to scheduled out hour on expected date
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
        if (! $attendance->exists) {
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

        // Double punch detection: If both records exist but duration is < 10 minutes,
        // it's likely a scanner error or accidental double punch
        if ($timeInRecord && $timeOutRecord && ! $sameRecord) {
            $duration = $timeInRecord['datetime']->diffInMinutes($timeOutRecord['datetime']);

            if ($duration < 10) {
                \Log::warning('Double punch detected', [
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'shift_date' => $shiftDate->format('Y-m-d'),
                    'time_in' => $timeInRecord['datetime']->format('H:i:s'),
                    'time_out' => $timeOutRecord['datetime']->format('H:i:s'),
                    'duration_minutes' => $duration,
                ]);

                // Store warning in attendance for admin visibility
                $doublePunchWarning = sprintf(
                    'DOUBLE PUNCH DETECTED: %s → %s (%d minutes apart). Time out has been cleared pending verification.',
                    $timeInRecord['datetime']->format('H:i:s'),
                    $timeOutRecord['datetime']->format('H:i:s'),
                    $duration
                );

                // Initialize warnings array if not set
                $existingWarnings = $attendance->warnings ?? [];
                $existingWarnings[] = $doublePunchWarning;
                $attendance->warnings = $existingWarnings;

                // Clear the time out - treat as no time out yet
                $timeOutRecord = null;
            }
        }

        // Maximum duration check: If duration > 20 hours, it's likely not a real time out
        // (could be next day's time in mismatched, or employee forgot to clock out)
        if ($timeInRecord && $timeOutRecord) {
            $duration = $timeInRecord['datetime']->diffInMinutes($timeOutRecord['datetime']);

            if ($duration > 1200) { // 20 hours = 1200 minutes
                $hours = round($duration / 60, 1);
                \Log::warning('Excessive shift duration detected', [
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'shift_date' => $shiftDate->format('Y-m-d'),
                    'time_in' => $timeInRecord['datetime']->format('Y-m-d H:i:s'),
                    'time_out' => $timeOutRecord['datetime']->format('Y-m-d H:i:s'),
                    'duration_hours' => $hours,
                ]);

                // Store warning in attendance for admin visibility
                $excessiveDurationWarning = sprintf(
                    'EXCESSIVE DURATION: %s → %s (%.1f hours). Time out has been cleared - likely a mismatched scan or forgot to clock out.',
                    $timeInRecord['datetime']->format('Y-m-d H:i'),
                    $timeOutRecord['datetime']->format('Y-m-d H:i'),
                    $hours
                );

                // Initialize warnings array if not set
                $existingWarnings = $attendance->warnings ?? [];
                $existingWarnings[] = $excessiveDurationWarning;
                $attendance->warnings = $existingWarnings;

                // Clear the time out - treat as missing bio out
                $timeOutRecord = null;
            }
        }

        // 24H Utility shift handling: Use First IN and Last OUT only
        // This handles the "Smoker" scenario where utility/guard staff scan multiple times
        // throughout their shift (smoke breaks, etc.) - ignore middle scans
        if ($schedule->shift_type === 'utility_24h' && $records->count() > 2) {
            $sortedRecords = $records->sortBy(fn ($r) => $r['datetime']->timestamp);
            $firstRecord = $sortedRecords->first();
            $lastRecord = $sortedRecords->last();

            // Only override if first and last are different
            if (! $firstRecord['datetime']->equalTo($lastRecord['datetime'])) {
                $timeInRecord = $firstRecord;
                $timeOutRecord = $lastRecord;

                \Log::info('24H Utility: Using first/last scans only', [
                    'user_id' => $user->id,
                    'total_scans' => $records->count(),
                    'first_scan' => $firstRecord['datetime']->format('H:i:s'),
                    'last_scan' => $lastRecord['datetime']->format('H:i:s'),
                ]);
            }
        }

        // Process time in
        if ($timeInRecord) {
            $actualTimeIn = $timeInRecord['datetime'];
            $tardyMinutes = (int) $scheduledTimeIn->diffInMinutes($actualTimeIn, false);

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
            // Zero out seconds for accurate minute comparison
            $timeDiffMinutes = (int) $scheduledTimeOut->copy()->second(0)->diffInMinutes($actualTimeOut->copy()->second(0), false);

            $bioInSiteId = $attendance->bio_in_site_id;
            $isCrossSite = $attendance->is_cross_site_bio ||
                          ($biometricSiteId && $schedule->site_id && ($biometricSiteId != $schedule->site_id)) ||
                          ($biometricSiteId && $bioInSiteId && ($biometricSiteId != $bioInSiteId));

            $attendance->actual_time_out = $actualTimeOut;
            $attendance->bio_out_site_id = $biometricSiteId;
            $attendance->is_cross_site_bio = $isCrossSite;

            // Check for undertime (any early departure)
            // Only count undertime if at least 1 minute early (to avoid 0 values from seconds)
            // 1-60 minutes early = undertime (0.25 pts)
            // 61+ minutes early = undertime_more_than_hour (0.50 pts)
            $undertimeMinutes = (int) abs($timeDiffMinutes);
            if ($timeDiffMinutes < 0 && $undertimeMinutes >= 1) {
                $attendance->undertime_minutes = $undertimeMinutes;
                $undertimeStatus = $undertimeMinutes > 60 ? 'undertime_more_than_hour' : 'undertime';
                if ($attendance->status === 'on_time') {
                    $attendance->status = $undertimeStatus;
                }
                // If primary status is tardy or half_day_absence, add undertime as secondary
                elseif (in_array($attendance->status, ['tardy', 'half_day_absence'])) {
                    $attendance->secondary_status = $undertimeStatus;
                }
            } else {
                // Not enough undertime (less than 1 minute), clear it
                $attendance->undertime_minutes = null;
            }

            // Check for overtime (worked beyond scheduled time out)
            // Only count overtime if worked more than 30 minutes beyond scheduled time out
            if ($timeDiffMinutes > 30) { // Positive means left late (overtime), threshold 30 minutes
                $attendance->overtime_minutes = $timeDiffMinutes;
            } else {
                // Not enough overtime (30 min threshold), clear it
                $attendance->overtime_minutes = null;
            }
        } else {
            // No TIME OUT - ensure overtime/undertime are cleared
            $attendance->overtime_minutes = null;
            $attendance->undertime_minutes = null;
        }

        // Determine final status based on what biometric records were found
        // Priority: Handle missing bio scenarios
        if (! $timeInRecord && ! $timeOutRecord) {
            // No bio at all
            $attendance->status = 'ncns';
            $attendance->secondary_status = null;
        } elseif (! $timeInRecord && $timeOutRecord) {
            // Has time OUT but missing time IN
            $attendance->status = 'failed_bio_in';
            $attendance->secondary_status = null;
        } elseif ($timeInRecord && ! $timeOutRecord) {
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
            if (! isset($attendance->secondary_status)) {
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
            $attendance->status = 'needs_manual_review';

            \Log::warning('Attendance flagged for manual review', [
                'user_id' => $user->id,
                'shift_date' => $shiftDate->format('Y-m-d'),
                'original_status' => $attendance->status,
                'warnings' => $scanAnalysis['warnings'],
            ]);
        }

        // 24H Utility shift: Override tardy/undertime logic
        // Utility staff don't follow strict schedules, so standard tardy/undertime
        // calculations would mess up their payroll. Only flag if total hours < 8.
        if ($schedule->shift_type === 'utility_24h') {
            // Clear tardy/undertime for utility shifts
            $attendance->tardy_minutes = null;
            $attendance->undertime_minutes = null;

            // Determine status based on total hours worked
            if ($attendance->actual_time_in && $attendance->actual_time_out) {
                $hoursWorked = $attendance->actual_time_in->diffInHours($attendance->actual_time_out);

                if ($hoursWorked >= 8) {
                    $attendance->status = 'on_time';
                    $attendance->secondary_status = null;
                } else {
                    // Less than 8 hours - flag as undertime for review
                    $attendance->status = 'undertime';
                    $attendance->secondary_status = null;

                    // Add informational warning
                    $existingWarnings = $attendance->warnings ?? [];
                    $existingWarnings[] = sprintf(
                        '24H UTILITY: Only %.1f hours worked (minimum 8 hours expected).',
                        $hoursWorked
                    );
                    $attendance->warnings = $existingWarnings;
                }
            }
        }

        // Calculate total minutes worked (deduct 60 min lunch if worked > 5 hours)
        // Use scheduled time in if employee clocked in early (early arrivals don't count as work time)
        // Use actual time out to capture overtime and undertime
        // If overtime exists but is NOT approved, cap work hours at scheduled time out
        if ($attendance->actual_time_in && $attendance->actual_time_out) {
            // Build the scheduled time in datetime for comparison
            $schedTimeInForCalc = Carbon::parse($shiftDate->format('Y-m-d').' '.$schedule->scheduled_time_in);

            // Adjust for graveyard shifts (scheduled time in is on next day)
            if ($isGraveyardShift) {
                $schedTimeInForCalc = Carbon::parse($expectedTimeInDate->format('Y-m-d').' '.$schedule->scheduled_time_in);
            }

            // Use the later of actual time in vs scheduled time in
            // This prevents early arrivals from counting as extra work time
            $effectiveTimeIn = $attendance->actual_time_in->greaterThan($schedTimeInForCalc)
                ? $attendance->actual_time_in
                : $schedTimeInForCalc;

            // Determine effective time out:
            // If overtime exists but is NOT approved, cap at scheduled time out
            // This ensures unapproved overtime doesn't count towards work hours
            $effectiveTimeOut = $attendance->actual_time_out;
            if ($attendance->overtime_minutes && $attendance->overtime_minutes > 0 && ! $attendance->overtime_approved) {
                // Cap at scheduled time out (don't include unapproved overtime in work hours)
                if ($attendance->actual_time_out->greaterThan($scheduledTimeOut)) {
                    $effectiveTimeOut = $scheduledTimeOut;
                }
            }

            $rawMinutes = $effectiveTimeIn->diffInMinutes($effectiveTimeOut);
            $lunchDeduction = ($rawMinutes / 60) > 5 ? 60 : 0;
            $attendance->total_minutes_worked = $rawMinutes - $lunchDeduction;
        } else {
            $attendance->total_minutes_worked = null;
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
     */
    protected function processTimeIn(
        User $user,
        EmployeeSchedule $schedule,
        Collection $records,
        Carbon $shiftDate,
        ?int $biometricSiteId = null
    ): array {
        // Build scheduled time in datetime
        $scheduledTimeIn = Carbon::parse($shiftDate->format('Y-m-d').' '.$schedule->scheduled_time_in);

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
            $tardyMinutes = (int) $scheduledTimeIn->diffInMinutes($actualTimeIn, false);

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
            if (! $attendance->is_advised) {
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

        if (! $attendance) {
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
        // But allow time-out updates for partially verified records
        if ($attendance->admin_verified && ! $attendance->is_partially_verified) {
            return [
                'matched' => true,
                'records_processed' => 0, // Skipped
                'errors' => [],
            ];
        }

        // Build scheduled time out datetime (could be next day for night shift)
        $scheduledTimeOut = Carbon::parse($shiftDate->format('Y-m-d').' '.$schedule->scheduled_time_out);

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
        );
        if ($timeOutRecord) {
            $actualTimeOut = $timeOutRecord['datetime'];
            // Calculate time difference: positive = overtime (left late), negative = undertime (left early)
            // Zero out seconds for accurate minute comparison
            $timeDiffMinutes = (int) $scheduledTimeOut->copy()->second(0)->diffInMinutes($actualTimeOut->copy()->second(0), false);

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
            // Use (int) cast to truncate, not round - gives exact minutes
            if ($timeDiffMinutes < -60) { // Negative means left early
                $updates['undertime_minutes'] = (int) abs($timeDiffMinutes);
                if ($attendance->status === 'on_time') {
                    $updates['status'] = 'undertime';
                }
            }

            // Check for overtime (worked beyond scheduled time out)
            // Only count overtime if worked more than 30 minutes beyond scheduled time out
            if ($timeDiffMinutes > 30) { // Positive means left late (overtime), threshold 30 minutes
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
     * @param  int  $tardyMinutes  Positive if late, negative if early
     * @param  int  $gracePeriodMinutes  Grace period from employee schedule (default 15)
     */
    protected function determineTimeInStatus(int $tardyMinutes, int $gracePeriodMinutes = 15): string
    {
        // More than grace period minutes late - half day absence (check this first)
        if ($tardyMinutes > $gracePeriodMinutes) {
            return 'half_day_absence';
        }

        // 1 minutes or more late, but within grace period - tardy
        if ($tardyMinutes >= 1) {
            return 'tardy';
        }

        // Less than 1 minute late - considered on time
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
     * Performance optimized: Uses pre-built lookup indexes instead of iterating all users.
     *
     * @param  Collection|null  $records  Optional: employee records to help with shift-based matching
     */
    protected function findUserByName(string $normalizedName, ?Collection $records = null): ?User
    {
        // Initialize cache on first call
        $this->initializeUserCache();

        // Handle comma-separated names like "Doe, John" -> normalize to "doe john"
        $normalizedName = str_replace(',', '', $normalizedName);
        $normalizedName = preg_replace('/\s+/', ' ', $normalizedName);
        $normalizedName = strtolower(trim($normalizedName));

        // Fast lookup using pre-built index
        if (isset($this->userLookupIndex[$normalizedName])) {
            $matches = $this->userLookupIndex[$normalizedName];

            // Single match - return immediately
            if (count($matches) === 1) {
                return $matches[0];
            }

            // Multiple matches - need disambiguation
            if (count($matches) > 1) {
                return $this->disambiguateMatches($matches, $normalizedName, $records);
            }
        }

        // No match found
        return null;
    }

    /**
     * Disambiguate between multiple users that match the same name pattern.
     *
     * @param  array  $matches  Array of matching users
     * @param  string  $normalizedName  The normalized name being searched
     * @param  Collection|null  $records  Optional biometric records for shift-based matching
     */
    protected function disambiguateMatches(array $matches, string $normalizedName, ?Collection $records = null): ?User
    {
        // Extract last name from the pattern to check for sibling disambiguation
        $parts = explode(' ', $normalizedName);
        $lastName = $parts[0];
        $suffix = $parts[1] ?? '';

        // Check if this is a single-initial pattern (1 character after last name)
        if (strlen($suffix) === 1) {
            // This is Pattern 3: Last name + first initial
            // Apply sibling disambiguation logic
            $sameInitialUsers = collect($matches);

            if ($sameInitialUsers->count() > 1) {
                // Sort by first 2 letters of first name (alphabetically first gets single-initial)
                $sortedUsers = $sameInitialUsers->sortBy(function ($u) {
                    return strtolower(substr(trim($u->first_name), 0, 2));
                });

                // First try shift-based matching if records are available
                if ($records && $records->count() > 0) {
                    $bestMatch = $this->findBestUserMatch($sameInitialUsers->all(), $records);
                    if ($bestMatch) {
                        return $bestMatch;
                    }
                }

                // Return user with alphabetically first 2-letter prefix
                return $sortedUsers->first();
            }
        }

        // For other patterns (just last name), try shift-based matching
        if ($records && $records->count() > 0) {
            $bestMatch = $this->findBestUserMatch($matches, $records);
            if ($bestMatch) {
                return $bestMatch;
            }
        }

        // Return first match as fallback
        return $matches[0] ?? null;
    }

    /**
     * Find best user match when multiple users share the same last name.
     * Uses the earliest biometric record time to match with user's shift schedule.
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

        if (! $earliestRecord) {
            return $users[0] ?? null;
        }

        $earliestHour = $earliestRecord['datetime']->hour;

        // Match users based on their schedule's shift time
        foreach ($users as $user) {
            $schedule = $user->employeeSchedules()->where('is_active', true)->first();
            if (! $schedule) {
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
     * @return int Number of points created
     */
    protected function generateAttendancePoints(Carbon $shiftDate): int
    {
        \Log::info('Generating attendance points', [
            'shift_date' => $shiftDate->format('Y-m-d'),
        ]);

        // Get attendance records for this shift date that need points
        // ONLY process records that are admin verified
        // Include records with secondary_status that may have point violations
        $attendances = Attendance::where('shift_date', $shiftDate)
            ->where('admin_verified', true)
            ->where(function ($query) {
                $query->whereIn('status', ['ncns', 'half_day_absence', 'tardy', 'undertime', 'undertime_more_than_hour'])
                    ->orWhereIn('secondary_status', ['undertime', 'undertime_more_than_hour']);
            })
            ->get();

        $pointsCreated = 0;
        $pointsToInsert = [];

        foreach ($attendances as $attendance) {
            // Determine point types and values for both primary and secondary status
            $primaryPointType = $this->mapStatusToPointType($attendance->status);
            $primaryPointValue = AttendancePoint::POINT_VALUES[$primaryPointType] ?? 0;

            $secondaryPointType = $attendance->secondary_status
                ? $this->mapStatusToPointType($attendance->secondary_status)
                : null;
            $secondaryPointValue = $secondaryPointType
                ? (AttendancePoint::POINT_VALUES[$secondaryPointType] ?? 0)
                : 0;

            // Determine which violation to use (higher point value wins)
            // This prevents double-penalty when user is both tardy AND has undertime in same shift
            $pointType = $primaryPointType;
            $pointValue = $primaryPointValue;
            $usedStatus = $attendance->status;

            if ($secondaryPointValue > $primaryPointValue) {
                $pointType = $secondaryPointType;
                $pointValue = $secondaryPointValue;
                $usedStatus = $attendance->secondary_status;

                \Log::info('Using secondary status for points (higher value)', [
                    'user_id' => $attendance->user_id,
                    'shift_date' => $attendance->shift_date->format('Y-m-d'),
                    'primary_status' => $attendance->status,
                    'primary_points' => $primaryPointValue,
                    'secondary_status' => $attendance->secondary_status,
                    'secondary_points' => $secondaryPointValue,
                    'used' => $usedStatus,
                ]);
            }

            // Skip if no valid point value
            if ($pointValue <= 0) {
                continue;
            }

            // Check if point already exists for this attendance record (any point type)
            $existingPoint = AttendancePoint::where('user_id', $attendance->user_id)
                ->where('shift_date', $attendance->shift_date)
                ->where('attendance_id', $attendance->id)
                ->first();

            if ($existingPoint) {
                // Point already exists for this shift, skip
                continue;
            }

            // Determine if NCNS/FTN (whole day absence without advice)
            $isNcnsOrFtn = $pointType === 'whole_day_absence' && ! $attendance->is_advised;

            // Calculate expiration date (6 months for standard, 1 year for NCNS/FTN)
            $expiresAt = $isNcnsOrFtn
                ? $shiftDate->copy()->addYear()
                : $shiftDate->copy()->addMonths(6);

            // Generate violation details with info about which violation was used
            $violationDetails = $this->generateViolationDetails($attendance, $usedStatus, $primaryPointType, $secondaryPointType);

            $pointsToInsert[] = [
                'user_id' => $attendance->user_id,
                'attendance_id' => $attendance->id,
                'shift_date' => $attendance->shift_date,
                'point_type' => $pointType,
                'points' => $pointValue,
                'status' => $usedStatus, // Store the status that was used for points
                'is_advised' => $attendance->is_advised ?? false,
                'is_excused' => false,
                'expires_at' => $expiresAt,
                'expiration_type' => $isNcnsOrFtn ? 'none' : 'sro',
                'is_expired' => false,
                'violation_details' => $violationDetails,
                'tardy_minutes' => $attendance->tardy_minutes,
                'undertime_minutes' => $attendance->undertime_minutes,
                'eligible_for_gbro' => ! $isNcnsOrFtn, // NCNS/FTN not eligible for GBRO
                'created_at' => now(),
                'updated_at' => now(),
            ];
            $pointsCreated++;
        }

        // Bulk insert all points at once for performance
        if (! empty($pointsToInsert)) {
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
     */
    protected function mapStatusToPointType(string $status): string
    {
        return match ($status) {
            'ncns' => 'whole_day_absence',
            'advised_absence' => 'whole_day_absence',
            'half_day_absence' => 'half_day_absence',
            'tardy' => 'tardy',
            'undertime' => 'undertime',
            'undertime_more_than_hour' => 'undertime_more_than_hour',
            default => 'whole_day_absence',
        };
    }

    /**
     * Generate detailed violation description.
     *
     * @param  string|null  $usedStatus  The status that was used for points (may differ from primary if secondary was higher)
     * @param  string|null  $primaryPointType  The primary point type
     * @param  string|null  $secondaryPointType  The secondary point type (if any)
     */
    protected function generateViolationDetails(
        Attendance $attendance,
        ?string $usedStatus = null,
        ?string $primaryPointType = null,
        ?string $secondaryPointType = null
    ): string {
        // Use the provided status or fall back to attendance status
        $status = $usedStatus ?? $attendance->status;

        $scheduledIn = $attendance->scheduled_time_in ? Carbon::parse($attendance->scheduled_time_in)->format('H:i') : 'N/A';
        $scheduledOut = $attendance->scheduled_time_out ? Carbon::parse($attendance->scheduled_time_out)->format('H:i') : 'N/A';
        $actualIn = $attendance->actual_time_in ? $attendance->actual_time_in->format('H:i') : 'No scan';
        $actualOut = $attendance->actual_time_out ? $attendance->actual_time_out->format('H:i') : 'No scan';

        // Get grace period from employee schedule
        $gracePeriod = $attendance->employeeSchedule?->grace_period_minutes ?? 15;

        $details = match ($status) {
            'ncns' => $attendance->is_advised
                ? "Failed to Notify (FTN): Employee did not report for work despite being advised. Scheduled: {$scheduledIn} - {$scheduledOut}. No biometric scans recorded."
                : "No Call, No Show (NCNS): Employee did not report for work and did not provide prior notice. Scheduled: {$scheduledIn} - {$scheduledOut}. No biometric scans recorded.",

            'half_day_absence' => sprintf(
                'Half-Day Absence: Arrived %d minutes late (more than %d minutes grace period). Scheduled: %s, Actual: %s.',
                $attendance->tardy_minutes ?? 0,
                $gracePeriod,
                $scheduledIn,
                $actualIn
            ),

            'tardy' => sprintf(
                'Tardy: Arrived %d minutes late. Scheduled time in: %s, Actual time in: %s.',
                $attendance->tardy_minutes ?? 0,
                $scheduledIn,
                $actualIn
            ),

            'undertime' => sprintf(
                'Undertime: Left %d minutes early (up to 1 hour before scheduled end). Scheduled: %s, Actual: %s.',
                $attendance->undertime_minutes ?? 0,
                $scheduledOut,
                $actualOut
            ),

            'undertime_more_than_hour' => sprintf(
                'Undertime (>1 Hour): Left %d minutes early (more than 1 hour before scheduled end). Scheduled: %s, Actual: %s.',
                $attendance->undertime_minutes ?? 0,
                $scheduledOut,
                $actualOut
            ),

            default => sprintf('Attendance violation on %s', Carbon::parse($attendance->shift_date)->format('Y-m-d')),
        };

        // Add note about other violation that was skipped (if both primary and secondary had point values)
        if ($primaryPointType && $secondaryPointType && $usedStatus !== $attendance->status) {
            $skippedType = $attendance->status;
            $skippedValue = AttendancePoint::POINT_VALUES[$primaryPointType] ?? 0;
            $details .= sprintf(
                ' [Note: Also had %s violation (%.2f pts) - only higher point value applied per shift]',
                $this->formatPointTypeLabel($skippedType),
                $skippedValue
            );
        } elseif ($attendance->secondary_status && $usedStatus === $attendance->status) {
            $skippedType = $attendance->secondary_status;
            $skippedValue = AttendancePoint::POINT_VALUES[$this->mapStatusToPointType($skippedType)] ?? 0;
            if ($skippedValue > 0) {
                $details .= sprintf(
                    ' [Note: Also had %s violation (%.2f pts) - only higher point value applied per shift]',
                    $this->formatPointTypeLabel($skippedType),
                    $skippedValue
                );
            }
        }

        return $details;
    }

    /**
     * Format point type as human-readable label.
     */
    protected function formatPointTypeLabel(string $status): string
    {
        return match ($status) {
            'ncns' => 'NCNS',
            'advised_absence' => 'Advised Absence',
            'half_day_absence' => 'Half-Day Absence',
            'tardy' => 'Tardy',
            'undertime' => 'Undertime',
            'undertime_more_than_hour' => 'Undertime (>1 Hour)',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    /**
     * Regenerate attendance points for a single attendance record.
     * Used when attendance status is manually changed.
     *
     * When both primary and secondary status have point values,
     * only the higher point value is applied (one violation per shift).
     */
    public function regeneratePointsForAttendance(Attendance $attendance): void
    {
        \Log::info('Regenerating attendance points for single record', [
            'attendance_id' => $attendance->id,
            'user_id' => $attendance->user_id,
            'status' => $attendance->status,
            'secondary_status' => $attendance->secondary_status,
            'shift_date' => $attendance->shift_date,
        ]);

        // Only generate points for statuses that require them
        $pointableStatuses = ['ncns', 'half_day_absence', 'tardy', 'undertime', 'undertime_more_than_hour', 'advised_absence'];

        // If is_set_home is enabled, skip undertime violations
        // This is for when employee was sent home early (not their fault)
        $undertimeStatuses = ['undertime', 'undertime_more_than_hour'];

        // Adjust statuses based on is_set_home flag
        $effectivePrimaryStatus = $attendance->status;
        $effectiveSecondaryStatus = $attendance->secondary_status;

        if ($attendance->is_set_home) {
            \Log::info('Set Home enabled - skipping undertime violations', [
                'attendance_id' => $attendance->id,
                'original_status' => $attendance->status,
                'original_secondary' => $attendance->secondary_status,
            ]);

            // If primary status is undertime, check if there's a secondary (like tardy) to use instead
            if (in_array($attendance->status, $undertimeStatuses)) {
                if ($attendance->secondary_status && ! in_array($attendance->secondary_status, $undertimeStatuses)) {
                    // Use secondary status as primary (e.g., tardy)
                    $effectivePrimaryStatus = $attendance->secondary_status;
                    $effectiveSecondaryStatus = null;
                } else {
                    // No valid secondary, skip point generation entirely
                    \Log::info('Set Home: No non-undertime status found, skipping points');

                    return;
                }
            }

            // If secondary status is undertime, clear it
            if (in_array($effectiveSecondaryStatus, $undertimeStatuses)) {
                $effectiveSecondaryStatus = null;
            }
        }

        if (! in_array($effectivePrimaryStatus, $pointableStatuses) &&
            ! in_array($effectiveSecondaryStatus, $pointableStatuses)) {
            \Log::info('Neither status requires points', [
                'status' => $effectivePrimaryStatus,
                'secondary_status' => $effectiveSecondaryStatus,
            ]);

            return;
        }

        // Determine point type and value for PRIMARY status
        // Only calculate primary point value if status is pointable, otherwise set to 0
        $primaryPointType = null;
        $primaryPointValue = 0;
        if (in_array($effectivePrimaryStatus, $pointableStatuses)) {
            $primaryPointType = $this->mapStatusToPointType($effectivePrimaryStatus);
            $primaryPointValue = AttendancePoint::POINT_VALUES[$primaryPointType] ?? 0;
        }

        // Determine point type and value for SECONDARY status (if exists)
        $secondaryPointType = null;
        $secondaryPointValue = 0;
        if ($effectiveSecondaryStatus && in_array($effectiveSecondaryStatus, $pointableStatuses)) {
            $secondaryPointType = $this->mapStatusToPointType($effectiveSecondaryStatus);
            $secondaryPointValue = AttendancePoint::POINT_VALUES[$secondaryPointType] ?? 0;
        }

        // Compare and use the higher point value (only one violation per shift)
        $usedStatus = $effectivePrimaryStatus;
        $pointType = $primaryPointType;
        $pointValue = $primaryPointValue;

        if ($secondaryPointValue > $primaryPointValue) {
            // Secondary status has higher point value, use it instead
            $usedStatus = $effectiveSecondaryStatus;
            $pointType = $secondaryPointType;
            $pointValue = $secondaryPointValue;
            \Log::info('Using secondary status for points (higher value)', [
                'primary_type' => $primaryPointType,
                'primary_value' => $primaryPointValue,
                'secondary_type' => $secondaryPointType,
                'secondary_value' => $secondaryPointValue,
            ]);
        } elseif ($secondaryPointValue > 0) {
            \Log::info('Using primary status for points (equal or higher value)', [
                'primary_type' => $primaryPointType,
                'primary_value' => $primaryPointValue,
                'secondary_type' => $secondaryPointType,
                'secondary_value' => $secondaryPointValue,
            ]);
        }

        if ($pointValue <= 0) {
            \Log::info('No point value for this type', ['point_type' => $pointType]);

            return;
        }

        $shiftDate = Carbon::parse($attendance->shift_date);

        // Determine if NCNS (whole day absence without advice)
        // Advised Absence gets 6-month expiration and is eligible for GBRO
        $isNcns = $pointType === 'whole_day_absence' && ! $attendance->is_advised;

        // Calculate expiration date:
        // - NCNS: 1 year expiration, not eligible for GBRO
        // - Advised Absence: 6 months expiration, eligible for GBRO
        // - All others: 6 months expiration, eligible for GBRO
        $expiresAt = $isNcns
            ? $shiftDate->copy()->addYear()
            : $shiftDate->copy()->addMonths(6);

        // Generate violation details with info about both violations if applicable
        $violationDetails = $this->generateViolationDetails(
            $attendance,
            $usedStatus,
            $primaryPointType,
            $secondaryPointType
        );

        // Create the attendance point (only ONE per shift - the higher value)
        AttendancePoint::create([
            'user_id' => $attendance->user_id,
            'attendance_id' => $attendance->id,
            'shift_date' => $attendance->shift_date,
            'point_type' => $pointType,
            'points' => $pointValue,
            'status' => $usedStatus,
            'is_advised' => $attendance->is_advised ?? false,
            'is_excused' => false,
            'expires_at' => $expiresAt,
            'expiration_type' => $isNcns ? 'none' : 'sro',
            'is_expired' => false,
            'violation_details' => $violationDetails,
            'tardy_minutes' => $attendance->tardy_minutes,
            'undertime_minutes' => $attendance->undertime_minutes,
            'eligible_for_gbro' => ! $isNcns, // Only NCNS is not eligible for GBRO, Advised Absence is eligible
        ]);

        \Log::info('Attendance point created (higher value applied)', [
            'attendance_id' => $attendance->id,
            'point_type' => $pointType,
            'points' => $pointValue,
            'used_status' => $usedStatus,
            'had_secondary' => ! empty($secondaryPointType),
        ]);
    }

    /**
     * Detect and create NCNS records for employees who are scheduled to work
     * but have no biometric scans in the uploaded file.
     *
     * @param  Collection  $records  All biometric records from the file
     */
    protected function detectAbsentEmployees(AttendanceUpload $upload, Collection $records): void
    {
        // Get all dates found in the biometric records
        $datesInFile = $records->pluck('datetime')
            ->map(fn ($dt) => $dt->format('Y-m-d'))
            ->unique()
            ->values();

        if ($datesInFile->isEmpty()) {
            return;
        }

        // Get all employees who are in the biometric file
        $employeesWithScans = $records->pluck('name')
            ->map(fn ($name) => $this->parser->normalizeName($name))
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
            if (! $schedule) {
                continue;
            }

            // Check each date in the file
            foreach ($datesInFile as $dateStr) {
                $date = Carbon::parse($dateStr);
                $dayName = $date->format('l');

                // Check if employee works on this day
                if (! $schedule->worksOnDay($dayName)) {
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
                $scheduledTimeIn = Carbon::parse($date->format('Y-m-d').' '.$schedule->scheduled_time_in);
                $scheduledTimeOut = Carbon::parse($date->format('Y-m-d').' '.$schedule->scheduled_time_out);

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
     * @param  Collection  $records  All biometric records for the employee
     * @param  EmployeeSchedule  $schedule  The employee's schedule
     * @param  Carbon  $shiftDate  The shift date
     * @param  mixed  $timeInRecord  The detected time in record (or null)
     * @param  mixed  $timeOutRecord  The detected time out record (or null)
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

        // Check for graveyard shift pattern (00:00-04:59 start time)
        $schedTimeIn = Carbon::parse($schedule->scheduled_time_in);
        $schedTimeOut = Carbon::parse($schedule->scheduled_time_out);
        $schedInHour = $schedTimeIn->hour;
        $schedOutHour = $schedTimeOut->hour;
        $isGraveyardShift = $schedInHour >= 0 && $schedInHour < 5 && $schedOutHour > $schedInHour;

        // Only consider records on or near the shift date
        $relevantRecords = $records->filter(function ($record) use ($shiftDate) {
            $scanDate = Carbon::parse($record['datetime']->format('Y-m-d'));

            return $scanDate->equalTo($shiftDate) ||
                   $scanDate->equalTo($shiftDate->copy()->subDay()) ||
                   $scanDate->equalTo($shiftDate->copy()->addDay());
        });

        $scanCount = $relevantRecords->count();

        // Build full scheduled datetime for accurate comparison
        // For graveyard shifts, the scheduled times are on the NEXT calendar day from shift_date
        if ($isGraveyardShift) {
            $nextDay = $shiftDate->copy()->addDay();
            $scheduledTimeInFull = Carbon::parse($nextDay->format('Y-m-d').' '.$schedule->scheduled_time_in);
            $scheduledTimeOutFull = Carbon::parse($nextDay->format('Y-m-d').' '.$schedule->scheduled_time_out);
        } else {
            $scheduledTimeInFull = Carbon::parse($shiftDate->format('Y-m-d').' '.$schedule->scheduled_time_in);
            $scheduledTimeOutFull = Carbon::parse($shiftDate->format('Y-m-d').' '.$schedule->scheduled_time_out);

            // Adjust time out if it's a next-day shift
            if ($schedTimeOut->lessThanOrEqualTo($schedTimeIn)) {
                $scheduledTimeOutFull->addDay();
            }
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
                $scanTimes = $relevantRecords->pluck('datetime')->map(fn ($dt) => $dt->format('Y-m-d H:i'))->join(', ');
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
        if ($scanCount == 2 && ! $timeInRecord && ! $timeOutRecord) {
            $firstScan = $relevantRecords->first()['datetime'];
            $lastScan = $relevantRecords->last()['datetime'];
            $hoursBetween = $firstScan->diffInHours($lastScan);

            if ($hoursBetween > 12) {
                $warnings[] = "Only 2 scans found with {$hoursBetween} hours gap ({$firstScan->format('H:i')} and {$lastScan->format('H:i')}), neither matches schedule";
                $needsReview = true;
            }
        }

        // Warning 5: No valid time IN or OUT detected despite having scans
        if ($scanCount > 0 && ! $timeInRecord && ! $timeOutRecord) {
            $scanTimes = $relevantRecords->pluck('datetime')->map(fn ($dt) => $dt->format('H:i'))->join(', ');
            $warnings[] = "No valid time IN/OUT detected from {$scanCount} scan(s) at: {$scanTimes}";
            $needsReview = true;
        }

        return [
            'needs_review' => $needsReview,
            'warnings' => $warnings,
        ];
    }

    /**
     * Recalculate total minutes worked for an attendance record.
     * This should be called when overtime approval status changes.
     *
     * If overtime exists but is NOT approved, work hours are capped at scheduled time out.
     * If overtime is approved, work hours include the overtime period.
     */
    public function recalculateTotalMinutesWorked(Attendance $attendance): void
    {
        // Can only calculate if both time in and time out exist
        if (! $attendance->actual_time_in || ! $attendance->actual_time_out) {
            $attendance->update(['total_minutes_worked' => null]);

            return;
        }

        // Check if lunch_used - if so, no lunch deduction (employee worked through lunch)
        $lunchUsed = $attendance->undertime_approval_reason === 'lunch_used';

        // Need schedule for proper calculation
        if (! $attendance->scheduled_time_in || ! $attendance->scheduled_time_out) {
            // No schedule - calculate raw time worked
            $rawMinutes = $attendance->actual_time_in->diffInMinutes($attendance->actual_time_out);
            $lunchDeduction = (! $lunchUsed && ($rawMinutes / 60) > 5) ? 60 : 0;
            $attendance->update(['total_minutes_worked' => $rawMinutes - $lunchDeduction]);

            return;
        }

        $shiftDate = Carbon::parse($attendance->shift_date);

        // Build scheduled times
        $scheduledTimeIn = Carbon::parse($shiftDate->format('Y-m-d').' '.$attendance->scheduled_time_in);
        $scheduledTimeOut = Carbon::parse($shiftDate->format('Y-m-d').' '.$attendance->scheduled_time_out);

        // Handle night shift - if scheduled time out is earlier than scheduled time in,
        // it means the shift ends the next day
        $scheduledIn = Carbon::parse($attendance->scheduled_time_in);
        $scheduledOut = Carbon::parse($attendance->scheduled_time_out);
        $isNightShift = $scheduledOut->format('H:i:s') < $scheduledIn->format('H:i:s');

        if ($isNightShift) {
            $scheduledTimeOut->addDay();
        }

        // Handle graveyard shift (00:00-04:59 start time)
        $schedInHour = $scheduledIn->hour;
        $isGraveyardShift = $schedInHour >= 0 && $schedInHour < 5;

        if ($isGraveyardShift) {
            // For graveyard shifts, adjust scheduled times to next day
            $scheduledTimeIn = $scheduledTimeIn->addDay();
            $scheduledTimeOut = $scheduledTimeOut->addDay();
        }

        // Use the later of actual time in vs scheduled time in
        // This prevents early arrivals from counting as extra work time
        $effectiveTimeIn = $attendance->actual_time_in->greaterThan($scheduledTimeIn)
            ? $attendance->actual_time_in
            : $scheduledTimeIn;

        // Determine effective time out:
        // If overtime exists but is NOT approved, cap at scheduled time out
        $effectiveTimeOut = $attendance->actual_time_out;
        if ($attendance->overtime_minutes && $attendance->overtime_minutes > 0 && ! $attendance->overtime_approved) {
            // Cap at scheduled time out (don't include unapproved overtime in work hours)
            if ($attendance->actual_time_out->greaterThan($scheduledTimeOut)) {
                $effectiveTimeOut = $scheduledTimeOut;
            }
        }

        $rawMinutes = $effectiveTimeIn->diffInMinutes($effectiveTimeOut);
        $lunchDeduction = (! $lunchUsed && ($rawMinutes / 60) > 5) ? 60 : 0;
        $attendance->update(['total_minutes_worked' => $rawMinutes - $lunchDeduction]);
    }
}
