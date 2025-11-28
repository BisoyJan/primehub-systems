<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class AttendanceFileParser
{
    /**
     * Parse the attendance TXT file.
     *
     * @param string $filePath Full path to the TXT file
     * @return Collection Collection of parsed records
     */
    public function parse(string $filePath): Collection
    {
        $content = file_get_contents($filePath);

        return $this->parseContent($content);
    }

    /**
     * Parse the content of the TXT file.
     *
     * @param string $content File content
     * @return Collection Collection of parsed records
     */
    public function parseContent(string $content): Collection
    {
        // Remove null bytes and other non-printable characters
        $content = str_replace("\0", '', $content);
        $content = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $content);

        // Normalize line endings (handle Windows CRLF, Mac CR, Unix LF)
        $content = str_replace("\r\n", "\n", $content);
        $content = str_replace("\r", "\n", $content);

        $lines = explode("\n", $content);

        return collect($lines)
            ->skip(1) // Skip header line
            ->filter(function ($line) {
                return !empty(trim($line));
            })
            ->map(function ($line) {
                return $this->parseLine($line);
            })
            ->filter(function ($record) {
                return $record !== null;
            });
    }

    /**
     * Parse a single line from the TXT file.
     *
     * Format: No	DevNo	UserId	Name	Mode	DateTime
     * Example: 1	1	10	Nodado A	FP	2025-11-05  05:50:25
     *
     * @param string $line
     * @return array|null
     */
    protected function parseLine(string $line): ?array
    {
        // Clean the line of any remaining null bytes or special characters
        $line = str_replace("\0", '', $line);
        $line = trim($line);

        if (empty($line)) {
            return null;
        }

        // Split by tab characters only (most reliable for biometric files)
        $columns = preg_split('/\t+/', $line);

        // If we don't have enough columns from tabs, try alternative parsing
        if (count($columns) < 6) {
            // Manual parsing: try to match tab-separated pattern
            if (preg_match('/^(.+?)\t(.+?)\t(.+?)\t(.+?)\t(.+?)\t(.+)$/', $line, $matches)) {
                $columns = array_slice($matches, 1); // Remove full match
            } else {
                // Fallback: split by 2+ spaces but be careful with datetime
                $parts = preg_split('/\s{2,}/', $line);
                if (count($parts) >= 6) {
                    // The datetime might be split, so reconstruct it from last 2 parts
                    $columns = array_slice($parts, 0, 5);
                    $columns[] = implode(' ', array_slice($parts, 5));
                } else {
                    return null;
                }
            }
        }

        if (count($columns) < 6) {
            return null;
        }

        $name = trim($columns[3] ?? '');
        $dateTimeStr = trim($columns[5] ?? '');

        // Remove any null bytes from the datetime string
        $dateTimeStr = str_replace("\0", '', $dateTimeStr);

        if (empty($name) || empty($dateTimeStr)) {
            return null;
        }

        try {
            // Handle double-space format in datetime: "2025-11-05  05:50:25"
            // Collapse multiple spaces into single space
            $dateTimeStr = preg_replace('/\s{2,}/', ' ', $dateTimeStr);

            // Additional safety: ensure no hidden characters
            $dateTimeStr = preg_replace('/[^\d\-\s:]/', '', $dateTimeStr);
            $dateTimeStr = trim($dateTimeStr);

            // Remove trailing digits that might be line numbers (e.g., "2025-01-13 22:26:181" -> "2025-01-13 22:26:18")
            // Valid format is YYYY-MM-DD HH:MM:SS (19 chars), anything extra is likely corrupted
            if (strlen($dateTimeStr) > 19) {
                // Extract only the datetime portion (YYYY-MM-DD HH:MM:SS)
                if (preg_match('/^(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})/', $dateTimeStr, $matches)) {
                    $dateTimeStr = $matches[1];
                }
            }

            $datetime = Carbon::createFromFormat('Y-m-d H:i:s', $dateTimeStr);

            if (!$datetime) {
                \Log::warning('Failed to create Carbon instance', [
                    'line' => $line,
                    'datetime_str' => $dateTimeStr,
                ]);
                return null;
            }
        } catch (\Exception $e) {
            // Log the error for debugging
            \Log::warning('Failed to parse datetime', [
                'line' => $line,
                'datetime_str' => $dateTimeStr,
                'columns_count' => count($columns),
                'error' => $e->getMessage()
            ]);
            return null;
        }

        return [
            'no' => $columns[0] ?? null,
            'dev_no' => $columns[1] ?? null,
            'user_id' => $columns[2] ?? null,
            'name' => $name,
            'mode' => $columns[4] ?? null,
            'datetime' => $datetime,
            'normalized_name' => $this->normalizeName($name),
        ];
    }

    /**
     * Normalize name for matching.
     * Removes extra spaces, periods, converts hyphens to spaces, and lowercase.
     * Handles edge cases like: "cabarliza m.", "Ogao-ogao", "Antonio g"
     *
     * @param string $name
     * @return string
     */
    public function normalizeName(string $name): string
    {
        // Trim whitespace
        $normalized = trim($name);

        // Remove periods
        $normalized = str_replace('.', '', $normalized);

        // Convert hyphens to spaces (e.g., "Ogao-ogao" -> "Ogao ogao")
        $normalized = str_replace('-', ' ', $normalized);

        // Collapse multiple spaces to single space
        $normalized = preg_replace('/\s+/', ' ', $normalized);

        // Convert to lowercase for case-insensitive matching
        $normalized = strtolower($normalized);

        return $normalized;
    }

    /**
     * Group records by employee name.
     *
     * @param Collection $records
     * @return Collection
     */
    public function groupByEmployee(Collection $records): Collection
    {
        return $records->groupBy('normalized_name')
            ->map(function ($employeeRecords) {
                return $employeeRecords->sortBy('datetime')->values();
            });
    }

    /**
     * Find time in record for a specific date.
     * Looks for the earliest reasonable record on the expected date.
     * Ignores scans that are too early (>2 hours before scheduled time).
     *
     * @param Collection $records Employee's bio records for a period
     * @param Carbon $expectedDate Expected date for time in
     * @param string|null $scheduledTimeIn Scheduled time in (HH:MM:SS) to filter unreasonable early scans
     * @return array|null
     */
    public function findTimeInRecord(Collection $records, Carbon $expectedDate, ?string $scheduledTimeIn = null): ?array
    {
        $targetDate = $expectedDate->format('Y-m-d');

        // Filter records on the target date and sort by time
        $filteredRecords = $records
            ->filter(function ($record) use ($targetDate) {
                return $record['datetime']->format('Y-m-d') === $targetDate;
            })
            ->sortBy(function ($record) {
                return $record['datetime']->timestamp;
            });

        if ($filteredRecords->isEmpty()) {
            return null;
        }

        // If scheduled time is provided, filter out unreasonably early scans
        // (more than 2 hours before scheduled time)
        if ($scheduledTimeIn !== null) {
            $scheduledDateTime = Carbon::parse($targetDate . ' ' . $scheduledTimeIn);
            $twoHoursBefore = $scheduledDateTime->copy()->subHours(2);

            $filteredRecords = $filteredRecords->filter(function ($record) use ($twoHoursBefore) {
                return $record['datetime']->greaterThanOrEqualTo($twoHoursBefore);
            });

            if ($filteredRecords->isEmpty()) {
                return null;
            }
        }

        // Return the first (earliest) valid record on the target date
        return $filteredRecords->first();
    }

    /**
     * Find time in record by time range on a specific date.
     * For night shifts: looks for records between start and end hour (e.g., 18:00-23:59).
     *
     * @param Collection $records Employee's bio records for a period
     * @param Carbon $expectedDate Expected date for time in
     * @param int $startHour Starting hour (e.g., 18 for 6 PM)
     * @param int $endHour Ending hour (e.g., 23 for 11 PM)
     * @return array|null
     */
    public function findTimeInRecordByTimeRange(
        Collection $records,
        Carbon $expectedDate,
        int $startHour,
        int $endHour
    ): ?array {
        $targetDate = $expectedDate->format('Y-m-d');

        // Find the first (earliest) record in the specified time range on the expected date
        $record = $records
            ->filter(function ($record) use ($targetDate, $startHour, $endHour) {
                if ($record['datetime']->format('Y-m-d') !== $targetDate) {
                    return false;
                }
                $hour = $record['datetime']->hour;
                return $hour >= $startHour && $hour <= $endHour;
            })
            ->sortBy(function ($record) {
                return $record['datetime']->timestamp;
            })
            ->first();

        return $record;
    }

    /**
     * Find time out record for a specific date.
     * For morning time outs (night shift ending in AM), looks for the EARLIEST record.
     * For afternoon/evening time outs, finds the record closest to scheduled time out
     * within a reasonable range (ignores extra scans that are too late).
     *
     * @param Collection $records Employee's bio records for a period
     * @param Carbon $expectedDate Expected date for time out
     * @param int|null $expectedHour Expected time out hour (0-23) to determine if morning or evening
     * @param string|null $scheduledTimeOut Scheduled time out (HH:MM:SS) for better matching
     * @return array|null
     */
    public function findTimeOutRecord(
        Collection $records,
        Carbon $expectedDate,
        ?int $expectedHour = null,
        ?string $scheduledTimeOut = null
    ): ?array {
        $targetDate = $expectedDate->format('Y-m-d');

        // Filter records on the target date
        $filteredRecords = $records
            ->filter(function ($record) use ($targetDate) {
                return $record['datetime']->format('Y-m-d') === $targetDate;
            })
            ->sortBy(function ($record) {
                return $record['datetime']->timestamp;
            });

        if ($filteredRecords->isEmpty()) {
            return null;
        }

        // For morning time outs (0-11 hours), we need to be careful
        // If expected hour is in early morning (0-5) without scheduled time,
        // this is likely a graveyard shift ending early morning - get FIRST record
        if ($expectedHour !== null && $expectedHour >= 0 && $expectedHour < 6 && $scheduledTimeOut === null) {
            // Early morning time out without schedule reference - get the earliest record
            return $filteredRecords->first();
        }

        // For all other cases (including morning time outs 0-11 with schedule),
        // find the best match using scheduled time
        if ($scheduledTimeOut !== null) {
            $scheduledDateTime = Carbon::parse($targetDate . ' ' . $scheduledTimeOut);

            // For graveyard/morning shifts where time out is in early/mid morning (0-11),
            // filter out very early scans that are likely TIME IN, not TIME OUT
            // Allow scans starting from 1 hour after midnight (01:00) to be considered
            if ($expectedHour !== null && $expectedHour >= 0 && $expectedHour < 12) {
                $earliestPossibleTimeOut = Carbon::parse($targetDate . ' 01:00:00');
                $filteredRecords = $filteredRecords->filter(function ($record) use ($earliestPossibleTimeOut) {
                    return $record['datetime']->greaterThanOrEqualTo($earliestPossibleTimeOut);
                });
            }

            // Find the record closest to scheduled time out, but not too late
            // Allow up to 6 hours after scheduled time (for extended overtime or unusual situations)
            // Anything later is likely next shift or scanner error
            $bestMatch = null;
            $smallestDiff = PHP_INT_MAX;

            foreach ($filteredRecords as $record) {
                $recordTime = $record['datetime'];
                $diffInMinutes = $scheduledDateTime->diffInMinutes($recordTime, false);

                // For morning time outs, accept scans BEFORE or AFTER scheduled time
                // (employee might leave early or late)
                // For afternoon/evening time outs, liberal acceptance window
                $isFarAfterScheduled = $diffInMinutes > 360; // 6 hours after

                if ($expectedHour !== null && $expectedHour >= 0 && $expectedHour < 12) {
                    // Morning time out: Accept scans up to 6 hours before and 6 hours after
                    // Very liberal to allow graveyard shift patterns
                    if ($diffInMinutes < -360 || $diffInMinutes > 360) {
                        continue;
                    }
                } else {
                    // Afternoon/evening time out: Accept scans up to 6 hours before and 6 hours after
                    // Liberal window to accommodate overtime and unusual scenarios
                    if ($diffInMinutes < -360 || $isFarAfterScheduled) {
                        continue;
                    }
                }

                // Find the record with smallest absolute difference from scheduled time
                $absDiff = abs($diffInMinutes);
                if ($absDiff < $smallestDiff) {
                    $smallestDiff = $absDiff;
                    $bestMatch = $record;
                }
            }

            // If we found a valid match within the time window, return it
            if ($bestMatch !== null) {
                return $bestMatch;
            }

            // No valid time out found within reasonable range
            // Return null to indicate missing time out
            return null;
        }

        // Fallback: get the last record (traditional behavior)
        return $filteredRecords->last();
    }

    /**
     * Find time out record within a specific time range on a date.
     * Looks for the latest record in the specified time range on the expected date.
     * Useful for shifts where both time in and time out are on the same date (e.g., graveyard shifts).
     *
     * @param Collection $records Employee's bio records for a period
     * @param Carbon $expectedDate Expected date for time out
     * @param int $startHour Start hour (inclusive)
     * @param int $endHour End hour (inclusive)
     * @return array|null
     */
    public function findTimeOutRecordByTimeRange(
        Collection $records,
        Carbon $expectedDate,
        int $startHour,
        int $endHour
    ): ?array {
        $targetDate = $expectedDate->format('Y-m-d');

        // Find the last (latest) record in the specified time range on the expected date
        $record = $records
            ->filter(function ($record) use ($targetDate, $startHour, $endHour) {
                if ($record['datetime']->format('Y-m-d') !== $targetDate) {
                    return false;
                }
                $hour = $record['datetime']->hour;
                return $hour >= $startHour && $hour <= $endHour;
            })
            ->sortBy(function ($record) {
                return $record['datetime']->timestamp;
            })
            ->last();

        return $record;
    }

    /**
     * Get statistics from parsed records.
     *
     * @param Collection $records
     * @return array
     */
    public function getStatistics(Collection $records): array
    {
        $uniqueNames = $records->pluck('normalized_name')->unique();

        return [
            'total_records' => $records->count(),
            'unique_employees' => $uniqueNames->count(),
            'date_range' => [
                'start' => $records->min('datetime'),
                'end' => $records->max('datetime'),
            ],
        ];
    }
}
