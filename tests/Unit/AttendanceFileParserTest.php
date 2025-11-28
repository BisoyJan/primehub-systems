<?php

namespace Tests\Unit;

use App\Services\AttendanceFileParser;
use Tests\TestCase;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;

class AttendanceFileParserTest extends TestCase
{
    protected AttendanceFileParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new AttendanceFileParser();
    }

    #[Test]
    public function it_parses_content_correctly()
    {
        $content = "No\tDevNo\tUserId\tName\tMode\tDateTime\n" .
                   "1\t1\t10\tNodado A\tFP\t2025-11-05  05:50:25\n" .
                   "2\t1\t20\tCabarliza M\tFP\t2025-11-05  06:15:30\n";

        $records = $this->parser->parseContent($content);

        $this->assertCount(2, $records);
        $this->assertEquals('Nodado A', $records->first()['name']);
        $this->assertEquals('nodado a', $records->first()['normalized_name']);
    }

    #[Test]
    public function it_normalizes_names_correctly()
    {
        $testCases = [
            'Nodado A' => 'nodado a',
            'Cabarliza M.' => 'cabarliza m',
            'Ogao-ogao' => 'ogao ogao',
            'ANTONIO  G' => 'antonio g',
            'dela Cruz' => 'dela cruz',
        ];

        foreach ($testCases as $input => $expected) {
            $result = $this->parser->normalizeName($input);
            $this->assertEquals($expected, $result, "Failed normalizing: {$input}");
        }
    }

    #[Test]
    public function it_parses_line_with_tabs()
    {
        $line = "1\t1\t10\tNodado A\tFP\t2025-11-05  05:50:25";
        $record = $this->invokeMethod($this->parser, 'parseLine', [$line]);

        $this->assertNotNull($record);
        $this->assertEquals('1', $record['no']);
        $this->assertEquals('1', $record['dev_no']);
        $this->assertEquals('10', $record['user_id']);
        $this->assertEquals('Nodado A', $record['name']);
        $this->assertEquals('FP', $record['mode']);
        $this->assertInstanceOf(Carbon::class, $record['datetime']);
        $this->assertEquals('2025-11-05 05:50:25', $record['datetime']->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function it_handles_double_spaces_in_datetime()
    {
        $line = "1\t1\t10\tNodado A\tFP\t2025-11-05  05:50:25";
        $record = $this->invokeMethod($this->parser, 'parseLine', [$line]);

        $this->assertNotNull($record);
        $this->assertEquals('2025-11-05 05:50:25', $record['datetime']->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function it_handles_trailing_digits_in_datetime()
    {
        $line = "1\t1\t10\tNodado A\tFP\t2025-11-05 05:50:251";
        $record = $this->invokeMethod($this->parser, 'parseLine', [$line]);

        $this->assertNotNull($record);
        $this->assertEquals('2025-11-05 05:50:25', $record['datetime']->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function it_returns_null_for_invalid_line()
    {
        $invalidLines = [
            '',
            '   ',
            "1\t2", // Not enough columns
            "invalid data",
        ];

        foreach ($invalidLines as $line) {
            $record = $this->invokeMethod($this->parser, 'parseLine', [$line]);
            $this->assertNull($record, "Should return null for: {$line}");
        }
    }

    #[Test]
    public function it_groups_records_by_employee()
    {
        $records = collect([
            ['name' => 'Nodado A', 'normalized_name' => 'nodado a', 'datetime' => Carbon::parse('2025-11-05 06:00:00')],
            ['name' => 'Nodado A', 'normalized_name' => 'nodado a', 'datetime' => Carbon::parse('2025-11-05 15:00:00')],
            ['name' => 'Cabarliza M', 'normalized_name' => 'cabarliza m', 'datetime' => Carbon::parse('2025-11-05 07:00:00')],
        ]);

        $grouped = $this->parser->groupByEmployee($records);

        $this->assertCount(2, $grouped);
        $this->assertCount(2, $grouped['nodado a']);
        $this->assertCount(1, $grouped['cabarliza m']);
    }

    #[Test]
    public function it_sorts_employee_records_by_datetime()
    {
        $records = collect([
            ['name' => 'Nodado A', 'normalized_name' => 'nodado a', 'datetime' => Carbon::parse('2025-11-05 15:00:00')],
            ['name' => 'Nodado A', 'normalized_name' => 'nodado a', 'datetime' => Carbon::parse('2025-11-05 06:00:00')],
        ]);

        $grouped = $this->parser->groupByEmployee($records);
        $nodadoRecords = collect($grouped['nodado a']);

        $this->assertEquals('06:00:00', $nodadoRecords->first()['datetime']->format('H:i:s'));
        $this->assertEquals('15:00:00', $nodadoRecords->last()['datetime']->format('H:i:s'));
    }

    #[Test]
    public function it_finds_time_in_record_on_expected_date()
    {
        $records = collect([
            ['datetime' => Carbon::parse('2025-11-05 06:00:00')],
            ['datetime' => Carbon::parse('2025-11-05 15:00:00')],
            ['datetime' => Carbon::parse('2025-11-06 06:00:00')],
        ]);

        $timeIn = $this->parser->findTimeInRecord($records, Carbon::parse('2025-11-05'), '06:00:00');

        $this->assertNotNull($timeIn);
        $this->assertEquals('2025-11-05 06:00:00', $timeIn['datetime']->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function it_returns_null_when_no_time_in_record_found()
    {
        $records = collect([
            ['datetime' => Carbon::parse('2025-11-05 06:00:00')],
        ]);

        $timeIn = $this->parser->findTimeInRecord($records, Carbon::parse('2025-11-06'));

        $this->assertNull($timeIn);
    }

    #[Test]
    public function it_handles_multiple_early_scans_for_time_in()
    {
        // Williams scenario: 08:00 scheduled, scans at 07:58 and 08:02
        $records = collect([
            ['datetime' => Carbon::parse('2025-11-10 07:58:15')], // 2 min early
            ['datetime' => Carbon::parse('2025-11-10 08:02:15')], // 2 min late
            ['datetime' => Carbon::parse('2025-11-10 17:10:25')], // time out
        ]);

        // Should take 07:58 (earliest valid scan, employee gets credit for arriving early)
        $timeIn = $this->parser->findTimeInRecord($records, Carbon::parse('2025-11-10'), '08:00:00');

        $this->assertNotNull($timeIn);
        $this->assertEquals('2025-11-10 07:58:15', $timeIn['datetime']->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function it_ignores_unreasonably_early_time_in_scans()
    {
        // Scenario: scan at 6 AM for 8 AM shift (2+ hours early - likely error or previous shift)
        $records = collect([
            ['datetime' => Carbon::parse('2025-11-10 05:30:00')], // 2.5 hours early - TOO EARLY
            ['datetime' => Carbon::parse('2025-11-10 07:55:00')], // 5 min early - VALID
            ['datetime' => Carbon::parse('2025-11-10 08:05:00')], // 5 min late - VALID
        ]);

        // Should skip 05:30 and take 07:55 (earliest within 2-hour window)
        $timeIn = $this->parser->findTimeInRecord($records, Carbon::parse('2025-11-10'), '08:00:00');

        $this->assertNotNull($timeIn);
        $this->assertEquals('2025-11-10 07:55:00', $timeIn['datetime']->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function it_finds_time_in_record_by_time_range()
    {
        $records = collect([
            ['datetime' => Carbon::parse('2025-11-05 06:00:00')],
            ['datetime' => Carbon::parse('2025-11-05 18:30:00')],
            ['datetime' => Carbon::parse('2025-11-05 22:15:00')],
            ['datetime' => Carbon::parse('2025-11-06 02:00:00')],
        ]);

        // Find time in for night shift (18:00-23:59)
        $timeIn = $this->parser->findTimeInRecordByTimeRange(
            $records,
            Carbon::parse('2025-11-05'),
            18,
            23
        );

        $this->assertNotNull($timeIn);
        $this->assertEquals('2025-11-05 18:30:00', $timeIn['datetime']->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function it_finds_earliest_record_in_time_range()
    {
        $records = collect([
            ['datetime' => Carbon::parse('2025-11-05 18:30:00')],
            ['datetime' => Carbon::parse('2025-11-05 22:15:00')],
            ['datetime' => Carbon::parse('2025-11-05 19:45:00')],
        ]);

        $timeIn = $this->parser->findTimeInRecordByTimeRange(
            $records,
            Carbon::parse('2025-11-05'),
            18,
            23
        );

        $this->assertEquals('2025-11-05 18:30:00', $timeIn['datetime']->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function it_finds_time_out_record_on_expected_date()
    {
        $records = collect([
            ['datetime' => Carbon::parse('2025-11-05 06:00:00')],
            ['datetime' => Carbon::parse('2025-11-05 15:00:00')],
            ['datetime' => Carbon::parse('2025-11-06 06:00:00')],
        ]);

        // Afternoon shift time out (15 = 3 PM), should get last record
        $timeOut = $this->parser->findTimeOutRecord($records, Carbon::parse('2025-11-05'), 15, '15:00:00');

        $this->assertNotNull($timeOut);
        $this->assertEquals('2025-11-05 15:00:00', $timeOut['datetime']->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function it_returns_latest_record_as_time_out()
    {
        $records = collect([
            ['datetime' => Carbon::parse('2025-11-05 06:00:00')],
            ['datetime' => Carbon::parse('2025-11-05 12:00:00')],
            ['datetime' => Carbon::parse('2025-11-05 15:00:00')],
        ]);

        // Afternoon shift time out (15 = 3 PM), should get last record
        $timeOut = $this->parser->findTimeOutRecord($records, Carbon::parse('2025-11-05'), 15, '15:00:00');

        $this->assertEquals('2025-11-05 15:00:00', $timeOut['datetime']->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function it_finds_time_out_record_by_time_range()
    {
        $records = collect([
            ['datetime' => Carbon::parse('2025-11-06 01:00:00')],
            ['datetime' => Carbon::parse('2025-11-06 04:30:00')],
            ['datetime' => Carbon::parse('2025-11-06 07:00:00')],
            ['datetime' => Carbon::parse('2025-11-06 10:00:00')],
        ]);

        // Find time out for graveyard shift (00:00-09:00)
        $timeOut = $this->parser->findTimeOutRecordByTimeRange(
            $records,
            Carbon::parse('2025-11-06'),
            0,
            9
        );

        $this->assertNotNull($timeOut);
        $this->assertEquals('2025-11-06 07:00:00', $timeOut['datetime']->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function it_returns_earliest_record_for_morning_time_out()
    {
        // Night shift scenario: 22:00-07:00
        // On Nov 12, we have both the morning time out (07:12) and evening time in for next shift (22:25)
        $records = collect([
            ['datetime' => Carbon::parse('2025-11-12 07:12:00')], // Time OUT for shift starting Nov 11
            ['datetime' => Carbon::parse('2025-11-12 22:25:00')], // Time IN for shift starting Nov 12
        ]);

        // For morning time out (7 AM), should get FIRST record (earliest in morning)
        $timeOut = $this->parser->findTimeOutRecord($records, Carbon::parse('2025-11-12'), 7, '07:00:00');

        $this->assertNotNull($timeOut);
        $this->assertEquals('2025-11-12 07:12:00', $timeOut['datetime']->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function it_handles_multiple_scans_and_picks_closest_to_scheduled_time_out()
    {
        // Williams scenario: 08:00-17:00 shift
        // Multiple scans on same day (left at 17:10, came back at 17:15)
        $records = collect([
            ['datetime' => Carbon::parse('2025-11-10 08:02:15')], // Time IN
            ['datetime' => Carbon::parse('2025-11-10 17:10:25')], // Time OUT (10 min late)
            ['datetime' => Carbon::parse('2025-11-10 17:15:25')], // Extra scan (came back?)
        ]);

        // Should pick 17:10:25 (closest to 17:00:00), not 17:15:25
        $timeOut = $this->parser->findTimeOutRecord($records, Carbon::parse('2025-11-10'), 17, '17:00:00');

        $this->assertNotNull($timeOut);
        $this->assertEquals('2025-11-10 17:10:25', $timeOut['datetime']->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function it_ignores_scans_too_late_for_time_out()
    {
        // Scenario: Employee left on time but has a scan 7 hours later (different shift)
        $records = collect([
            ['datetime' => Carbon::parse('2025-11-10 08:00:00')], // Time IN
            ['datetime' => Carbon::parse('2025-11-10 17:05:00')], // Time OUT (on time)
            ['datetime' => Carbon::parse('2025-11-11 00:10:00')], // Too late (>6 hours)
        ]);

        // Should pick 17:05, ignoring 00:10 as it's >6 hours after scheduled time
        $timeOut = $this->parser->findTimeOutRecord($records, Carbon::parse('2025-11-10'), 17, '17:00:00');

        $this->assertNotNull($timeOut);
        $this->assertEquals('2025-11-10 17:05:00', $timeOut['datetime']->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function it_handles_overtime_scans_within_reasonable_range()
    {
        // Williams overtime scenario: 08:00-17:00 shift with 2 hours overtime
        // Employee works late but also has intermediate scan
        $records = collect([
            ['datetime' => Carbon::parse('2025-11-10 08:00:00')], // Time IN
            ['datetime' => Carbon::parse('2025-11-10 17:10:25')], // Regular time out (10 min late)
            ['datetime' => Carbon::parse('2025-11-10 19:00:00')], // Overtime scan (2hr late, within 6hr)
        ]);

        // Should pick 17:10:25 (closest to scheduled 17:00), not 19:00
        // Both are within 6-hour window, but 17:10 is closer
        $timeOut = $this->parser->findTimeOutRecord($records, Carbon::parse('2025-11-10'), 17, '17:00:00');

        $this->assertNotNull($timeOut);
        $this->assertEquals('2025-11-10 17:10:25', $timeOut['datetime']->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function it_returns_null_when_only_scan_is_too_late_indicating_missing_time_out()
    {
        // Williams missing time out: 08:00-17:00 shift
        // Only scan after time in is 7+ hours late (way beyond 6-hour window)
        $records = collect([
            ['datetime' => Carbon::parse('2025-11-10 07:58:15')], // Time IN
            ['datetime' => Carbon::parse('2025-11-11 00:30:00')], // Way too late (450 min = 7.5 hours)
        ]);

        // Should return NULL - no valid time out within 6-hour window
        // This indicates "Failed Bio Out" scenario
        $timeOut = $this->parser->findTimeOutRecord($records, Carbon::parse('2025-11-10'), 17, '17:00:00');

        $this->assertNull($timeOut);
    }

    #[Test]
    public function it_accepts_six_hour_overtime_as_valid_time_out()
    {
        // Williams legitimate overtime: 08:00-17:00 shift, worked until 23:00
        // 6 hours of overtime (exactly at threshold)
        $records = collect([
            ['datetime' => Carbon::parse('2025-11-10 07:58:15')], // Time IN
            ['datetime' => Carbon::parse('2025-11-10 23:00:00')], // Time OUT (exactly 360 min = 6 hours OT)
        ]);

        // Should accept 23:00 as valid time out (exactly at 6-hour threshold)
        $timeOut = $this->parser->findTimeOutRecord($records, Carbon::parse('2025-11-10'), 17, '17:00:00');

        $this->assertNotNull($timeOut);
        $this->assertEquals('2025-11-10 23:00:00', $timeOut['datetime']->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function it_returns_statistics_from_records()
    {
        $records = collect([
            [
                'normalized_name' => 'nodado a',
                'datetime' => Carbon::parse('2025-11-05 06:00:00')
            ],
            [
                'normalized_name' => 'nodado a',
                'datetime' => Carbon::parse('2025-11-05 15:00:00')
            ],
            [
                'normalized_name' => 'cabarliza m',
                'datetime' => Carbon::parse('2025-11-06 07:00:00')
            ],
        ]);

        $stats = $this->parser->getStatistics($records);

        $this->assertEquals(3, $stats['total_records']);
        $this->assertEquals(2, $stats['unique_employees']);
        $this->assertEquals('2025-11-05 06:00:00', $stats['date_range']['start']->format('Y-m-d H:i:s'));
        $this->assertEquals('2025-11-06 07:00:00', $stats['date_range']['end']->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function it_skips_header_line_when_parsing_content()
    {
        $content = "No\tDevNo\tUserId\tName\tMode\tDateTime\n" .
                   "1\t1\t10\tNodado A\tFP\t2025-11-05  05:50:25\n";

        $records = $this->parser->parseContent($content);

        $this->assertCount(1, $records);
        $this->assertEquals('Nodado A', $records->first()['name']);
    }

    #[Test]
    public function it_handles_empty_lines_in_content()
    {
        $content = "No\tDevNo\tUserId\tName\tMode\tDateTime\n" .
                   "1\t1\t10\tNodado A\tFP\t2025-11-05  05:50:25\n" .
                   "\n" .
                   "2\t1\t20\tCabarliza M\tFP\t2025-11-05  06:15:30\n" .
                   "   \n";

        $records = $this->parser->parseContent($content);

        $this->assertCount(2, $records);
    }

    #[Test]
    public function it_handles_different_line_endings()
    {
        $contentCRLF = "No\tDevNo\tUserId\tName\tMode\tDateTime\r\n1\t1\t10\tNodado A\tFP\t2025-11-05  05:50:25\r\n";
        $contentLF = "No\tDevNo\tUserId\tName\tMode\tDateTime\n1\t1\t10\tNodado A\tFP\t2025-11-05  05:50:25\n";
        $contentCR = "No\tDevNo\tUserId\tName\tMode\tDateTime\r1\t1\t10\tNodado A\tFP\t2025-11-05  05:50:25\r";

        $recordsCRLF = $this->parser->parseContent($contentCRLF);
        $recordsLF = $this->parser->parseContent($contentLF);
        $recordsCR = $this->parser->parseContent($contentCR);

        $this->assertCount(1, $recordsCRLF);
        $this->assertCount(1, $recordsLF);
        $this->assertCount(1, $recordsCR);
    }

    #[Test]
    public function it_removes_null_bytes_from_content()
    {
        $content = "No\tDevNo\tUserId\tName\tMode\tDateTime\n" .
                   "1\t1\t10\0\tNodado A\tFP\t2025-11-05  05:50:25\0\n";

        $records = $this->parser->parseContent($content);

        $this->assertCount(1, $records);
        $this->assertEquals('Nodado A', $records->first()['name']);
    }

    /**
     * Helper method to invoke protected/private methods for testing.
     */
    protected function invokeMethod($object, string $methodName, array $parameters = [])
    {
        $reflection = new \ReflectionClass(get_class($object));
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $parameters);
    }
}
