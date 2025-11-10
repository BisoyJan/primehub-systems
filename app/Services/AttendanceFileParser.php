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
     * Looks for the earliest record on the expected date.
     *
     * @param Collection $records Employee's bio records for a period
     * @param Carbon $expectedDate Expected date for time in
     * @return array|null
     */
    public function findTimeInRecord(Collection $records, Carbon $expectedDate): ?array
    {
        $targetDate = $expectedDate->format('Y-m-d');

        // Find the first (earliest) record on the expected date
        $record = $records
            ->filter(function ($record) use ($targetDate) {
                return $record['datetime']->format('Y-m-d') === $targetDate;
            })
            ->sortBy(function ($record) {
                return $record['datetime']->timestamp;
            })
            ->first();

        return $record;
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
     * Looks for the latest record on the expected date.
     *
     * @param Collection $records Employee's bio records for a period
     * @param Carbon $expectedDate Expected date for time out
     * @return array|null
     */
    public function findTimeOutRecord(Collection $records, Carbon $expectedDate): ?array
    {
        $targetDate = $expectedDate->format('Y-m-d');

        // Find the last (latest) record on the expected date
        $record = $records
            ->filter(function ($record) use ($targetDate) {
                return $record['datetime']->format('Y-m-d') === $targetDate;
            })
            ->sortBy(function ($record) {
                return $record['datetime']->timestamp;
            })
            ->last();

        return $record;
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
