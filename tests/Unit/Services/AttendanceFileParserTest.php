<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\AttendanceFileParser;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AttendanceFileParserTest extends TestCase
{
    private AttendanceFileParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new AttendanceFileParser();
    }

    #[Test]
    public function it_normalizes_name_correctly(): void
    {
        $this->assertEquals('cabarliza a', $this->parser->normalizeName('Cabarliza A.'));
        $this->assertEquals('ogao ogao', $this->parser->normalizeName('Ogao-ogao'));
        $this->assertEquals('antonio g', $this->parser->normalizeName('Antonio G'));
        $this->assertEquals('rosel', $this->parser->normalizeName('Rosel'));
    }

    #[Test]
    public function it_removes_periods_from_names(): void
    {
        $normalized = $this->parser->normalizeName('Cabarliza M.');

        $this->assertEquals('cabarliza m', $normalized);
        $this->assertStringNotContainsString('.', $normalized);
    }

    #[Test]
    public function it_converts_hyphens_to_spaces(): void
    {
        $normalized = $this->parser->normalizeName('Ogao-Ogao');

        $this->assertEquals('ogao ogao', $normalized);
    }

    #[Test]
    public function it_collapses_multiple_spaces(): void
    {
        $normalized = $this->parser->normalizeName('John    Doe');

        $this->assertEquals('john doe', $normalized);
    }

    #[Test]
    public function it_converts_to_lowercase(): void
    {
        $normalized = $this->parser->normalizeName('JOHN DOE');

        $this->assertEquals('john doe', $normalized);
    }

    #[Test]
    public function it_parses_valid_content_line(): void
    {
        $content = "No\tDevNo\tUserId\tName\tMode\tDateTime\n";
        $content .= "1\t1\t10\tNodado A\tFP\t2025-11-05 05:50:25";

        $records = $this->parser->parseContent($content);

        $this->assertCount(1, $records);
        $record = $records->first();
        $this->assertEquals('Nodado A', $record['name']);
        $this->assertEquals('nodado a', $record['normalized_name']);
        $this->assertInstanceOf(Carbon::class, $record['datetime']);
    }

    #[Test]
    public function it_handles_double_space_in_datetime(): void
    {
        $content = "No\tDevNo\tUserId\tName\tMode\tDateTime\n";
        $content .= "1\t1\t10\tJohn Doe\tFP\t2025-11-05  05:50:25"; // Double space

        $records = $this->parser->parseContent($content);

        $this->assertCount(1, $records);
        $this->assertInstanceOf(Carbon::class, $records->first()['datetime']);
    }

    #[Test]
    public function it_skips_header_line(): void
    {
        $content = "No\tDevNo\tUserId\tName\tMode\tDateTime\n";
        $content .= "1\t1\t10\tJohn Doe\tFP\t2025-11-05 05:50:25";

        $records = $this->parser->parseContent($content);

        $this->assertCount(1, $records); // Only data line, not header
    }

    #[Test]
    public function it_skips_empty_lines(): void
    {
        $content = "No\tDevNo\tUserId\tName\tMode\tDateTime\n";
        $content .= "1\t1\t10\tJohn Doe\tFP\t2025-11-05 05:50:25\n";
        $content .= "\n"; // Empty line
        $content .= "2\t1\t11\tJane Smith\tFP\t2025-11-05 06:00:00";

        $records = $this->parser->parseContent($content);

        $this->assertCount(2, $records);
    }

    #[Test]
    public function it_groups_records_by_employee(): void
    {
        $records = collect([
            ['normalized_name' => 'john doe', 'datetime' => Carbon::parse('2025-11-05 08:00:00')],
            ['normalized_name' => 'jane smith', 'datetime' => Carbon::parse('2025-11-05 08:00:00')],
            ['normalized_name' => 'john doe', 'datetime' => Carbon::parse('2025-11-05 17:00:00')],
        ]);

        $grouped = $this->parser->groupByEmployee($records);

        $this->assertCount(2, $grouped);
        $this->assertCount(2, $grouped->get('john doe'));
        $this->assertCount(1, $grouped->get('jane smith'));
    }

    #[Test]
    public function it_sorts_grouped_records_by_datetime(): void
    {
        $records = collect([
            ['normalized_name' => 'john doe', 'datetime' => Carbon::parse('2025-11-05 17:00:00')],
            ['normalized_name' => 'john doe', 'datetime' => Carbon::parse('2025-11-05 08:00:00')],
        ]);

        $grouped = $this->parser->groupByEmployee($records);
        /** @var Collection $johnRecords */
        $johnRecords = $grouped->get('john doe');

        $this->assertEquals('08:00:00', $johnRecords->first()['datetime']->format('H:i:s'));
        $this->assertEquals('17:00:00', $johnRecords->last()['datetime']->format('H:i:s'));
    }

    #[Test]
    public function it_finds_time_in_record_on_expected_date(): void
    {
        $records = collect([
            ['datetime' => Carbon::parse('2025-11-05 08:00:00')],
            ['datetime' => Carbon::parse('2025-11-05 17:00:00')],
        ]);

        $timeIn = $this->parser->findTimeInRecord($records, Carbon::parse('2025-11-05'));

        $this->assertNotNull($timeIn);
        $this->assertEquals('08:00:00', $timeIn['datetime']->format('H:i:s'));
    }

    #[Test]
    public function it_returns_null_when_no_time_in_record_on_date(): void
    {
        $records = collect([
            ['datetime' => Carbon::parse('2025-11-04 08:00:00')],
        ]);

        $timeIn = $this->parser->findTimeInRecord($records, Carbon::parse('2025-11-05'));

        $this->assertNull($timeIn);
    }

    #[Test]
    public function it_finds_time_in_record_by_time_range(): void
    {
        $records = collect([
            ['datetime' => Carbon::parse('2025-11-05 22:00:00')],
            ['datetime' => Carbon::parse('2025-11-05 23:30:00')],
            ['datetime' => Carbon::parse('2025-11-06 07:00:00')],
        ]);

        $timeIn = $this->parser->findTimeInRecordByTimeRange($records, Carbon::parse('2025-11-05'), 18, 23);

        $this->assertNotNull($timeIn);
        $this->assertEquals('22:00:00', $timeIn['datetime']->format('H:i:s'));
    }

    #[Test]
    public function it_finds_time_out_record_on_expected_date(): void
    {
        $records = collect([
            ['datetime' => Carbon::parse('2025-11-06 08:00:00')],
            ['datetime' => Carbon::parse('2025-11-06 17:00:00')],
        ]);

        $timeOut = $this->parser->findTimeOutRecord($records, Carbon::parse('2025-11-06'));

        $this->assertNotNull($timeOut);
        $this->assertEquals('17:00:00', $timeOut['datetime']->format('H:i:s'));
    }

    #[Test]
    public function it_finds_time_out_record_by_time_range(): void
    {
        $records = collect([
            ['datetime' => Carbon::parse('2025-11-06 05:00:00')],
            ['datetime' => Carbon::parse('2025-11-06 07:00:00')],
        ]);

        $timeOut = $this->parser->findTimeOutRecordByTimeRange($records, Carbon::parse('2025-11-06'), 0, 9);

        $this->assertNotNull($timeOut);
        $this->assertEquals('07:00:00', $timeOut['datetime']->format('H:i:s'));
    }

    #[Test]
    public function it_gets_statistics_from_records(): void
    {
        $records = collect([
            ['normalized_name' => 'john doe', 'datetime' => Carbon::parse('2025-11-05 08:00:00')],
            ['normalized_name' => 'jane smith', 'datetime' => Carbon::parse('2025-11-05 09:00:00')],
            ['normalized_name' => 'john doe', 'datetime' => Carbon::parse('2025-11-05 17:00:00')],
        ]);

        $stats = $this->parser->getStatistics($records);

        $this->assertEquals(3, $stats['total_records']);
        $this->assertEquals(2, $stats['unique_employees']);
        $this->assertArrayHasKey('date_range', $stats);
    }

    #[Test]
    public function it_handles_corrupted_datetime_with_trailing_digits(): void
    {
        $content = "No\tDevNo\tUserId\tName\tMode\tDateTime\n";
        $content .= "1\t1\t10\tJohn Doe\tFP\t2025-11-05 08:00:181"; // Trailing digit

        $records = $this->parser->parseContent($content);

        $this->assertCount(1, $records);
        $this->assertEquals('08:00:18', $records->first()['datetime']->format('H:i:s'));
    }

    #[Test]
    public function it_removes_null_bytes_from_content(): void
    {
        $content = "No\tDevNo\tUserId\tName\tMode\tDateTime\n";
        $content .= "1\t1\t10\tJohn\x00Doe\tFP\t2025-11-05 08:00:00";

        $records = $this->parser->parseContent($content);

        $this->assertCount(1, $records);
    }

    #[Test]
    public function it_normalizes_line_endings(): void
    {
        $content = "No\tDevNo\tUserId\tName\tMode\tDateTime\r\n"; // Windows CRLF
        $content .= "1\t1\t10\tJohn Doe\tFP\t2025-11-05 08:00:00";

        $records = $this->parser->parseContent($content);

        $this->assertCount(1, $records);
    }
}
