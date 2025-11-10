<?php

namespace Tests\Unit;

use App\Services\AttendanceFileParser;
use Tests\TestCase;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class AttendanceFileParserTest extends TestCase
{
    protected AttendanceFileParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new AttendanceFileParser();
    }

    /** @test */
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

    /** @test */
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

    /** @test */
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

    /** @test */
    public function it_handles_double_spaces_in_datetime()
    {
        $line = "1\t1\t10\tNodado A\tFP\t2025-11-05  05:50:25";
        $record = $this->invokeMethod($this->parser, 'parseLine', [$line]);

        $this->assertNotNull($record);
        $this->assertEquals('2025-11-05 05:50:25', $record['datetime']->format('Y-m-d H:i:s'));
    }

    /** @test */
    public function it_handles_trailing_digits_in_datetime()
    {
        $line = "1\t1\t10\tNodado A\tFP\t2025-11-05 05:50:251";
        $record = $this->invokeMethod($this->parser, 'parseLine', [$line]);

        $this->assertNotNull($record);
        $this->assertEquals('2025-11-05 05:50:25', $record['datetime']->format('Y-m-d H:i:s'));
    }

    /** @test */
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

    /** @test */
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

    /** @test */
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

    /** @test */
    public function it_finds_time_in_record_on_expected_date()
    {
        $records = collect([
            ['datetime' => Carbon::parse('2025-11-05 06:00:00')],
            ['datetime' => Carbon::parse('2025-11-05 15:00:00')],
            ['datetime' => Carbon::parse('2025-11-06 06:00:00')],
        ]);

        $timeIn = $this->parser->findTimeInRecord($records, Carbon::parse('2025-11-05'));

        $this->assertNotNull($timeIn);
        $this->assertEquals('2025-11-05 06:00:00', $timeIn['datetime']->format('Y-m-d H:i:s'));
    }

    /** @test */
    public function it_returns_null_when_no_time_in_record_found()
    {
        $records = collect([
            ['datetime' => Carbon::parse('2025-11-05 06:00:00')],
        ]);

        $timeIn = $this->parser->findTimeInRecord($records, Carbon::parse('2025-11-06'));

        $this->assertNull($timeIn);
    }

    /** @test */
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

    /** @test */
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

    /** @test */
    public function it_finds_time_out_record_on_expected_date()
    {
        $records = collect([
            ['datetime' => Carbon::parse('2025-11-05 06:00:00')],
            ['datetime' => Carbon::parse('2025-11-05 15:00:00')],
            ['datetime' => Carbon::parse('2025-11-06 06:00:00')],
        ]);

        $timeOut = $this->parser->findTimeOutRecord($records, Carbon::parse('2025-11-05'));

        $this->assertNotNull($timeOut);
        $this->assertEquals('2025-11-05 15:00:00', $timeOut['datetime']->format('Y-m-d H:i:s'));
    }

    /** @test */
    public function it_returns_latest_record_as_time_out()
    {
        $records = collect([
            ['datetime' => Carbon::parse('2025-11-05 06:00:00')],
            ['datetime' => Carbon::parse('2025-11-05 12:00:00')],
            ['datetime' => Carbon::parse('2025-11-05 15:00:00')],
        ]);

        $timeOut = $this->parser->findTimeOutRecord($records, Carbon::parse('2025-11-05'));

        $this->assertEquals('2025-11-05 15:00:00', $timeOut['datetime']->format('Y-m-d H:i:s'));
    }

    /** @test */
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

    /** @test */
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

    /** @test */
    public function it_skips_header_line_when_parsing_content()
    {
        $content = "No\tDevNo\tUserId\tName\tMode\tDateTime\n" .
                   "1\t1\t10\tNodado A\tFP\t2025-11-05  05:50:25\n";

        $records = $this->parser->parseContent($content);

        $this->assertCount(1, $records);
        $this->assertEquals('Nodado A', $records->first()['name']);
    }

    /** @test */
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

    /** @test */
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

    /** @test */
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
