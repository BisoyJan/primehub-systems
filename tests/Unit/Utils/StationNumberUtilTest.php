<?php

declare(strict_types=1);

namespace Tests\Unit\Utils;

use App\Utils\StationNumberUtil;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StationNumberUtilTest extends TestCase
{
    #[Test]
    public function it_has_regex_pattern_constant(): void
    {
        $this->assertNotEmpty(StationNumberUtil::REGEX_PATTERN);
        $this->assertIsString(StationNumberUtil::REGEX_PATTERN);
    }

    #[Test]
    public function it_matches_simple_station_number(): void
    {
        $stationNumber = 'PC-1';

        preg_match(StationNumberUtil::REGEX_PATTERN, $stationNumber, $matches);

        $this->assertCount(5, $matches);
        $this->assertEquals('PC-', $matches[1]); // prefix
        $this->assertEquals('1', $matches[2]); // number
        $this->assertEquals('', $matches[3]); // letter
        $this->assertEquals('', $matches[4]); // suffix
    }

    #[Test]
    public function it_matches_station_number_with_letter(): void
    {
        $stationNumber = 'PC-1A';

        preg_match(StationNumberUtil::REGEX_PATTERN, $stationNumber, $matches);

        $this->assertEquals('PC-', $matches[1]); // prefix
        $this->assertEquals('1', $matches[2]); // number
        $this->assertEquals('A', $matches[3]); // letter
        $this->assertEquals('', $matches[4]); // suffix
    }

    #[Test]
    public function it_matches_station_number_with_padded_zeros(): void
    {
        $stationNumber = 'ST-001';

        preg_match(StationNumberUtil::REGEX_PATTERN, $stationNumber, $matches);

        $this->assertEquals('ST-', $matches[1]); // prefix
        $this->assertEquals('001', $matches[2]); // number (preserves padding)
        $this->assertEquals('', $matches[3]); // letter
        $this->assertEquals('', $matches[4]); // suffix
    }

    #[Test]
    public function it_matches_station_number_with_suffix(): void
    {
        $stationNumber = 'WS-10B-FLOOR2';

        preg_match(StationNumberUtil::REGEX_PATTERN, $stationNumber, $matches);

        $this->assertEquals('WS-', $matches[1]); // prefix
        $this->assertEquals('10', $matches[2]); // number
        $this->assertEquals('B', $matches[3]); // letter
        $this->assertEquals('-FLOOR2', $matches[4]); // suffix
    }

    #[Test]
    public function it_matches_number_only(): void
    {
        $stationNumber = '123';

        preg_match(StationNumberUtil::REGEX_PATTERN, $stationNumber, $matches);

        $this->assertEquals('', $matches[1]); // prefix (empty)
        $this->assertEquals('123', $matches[2]); // number
        $this->assertEquals('', $matches[3]); // letter
        $this->assertEquals('', $matches[4]); // suffix
    }

    #[Test]
    public function it_matches_number_with_letter_no_prefix(): void
    {
        $stationNumber = '5A';

        preg_match(StationNumberUtil::REGEX_PATTERN, $stationNumber, $matches);

        $this->assertEquals('', $matches[1]); // prefix (empty)
        $this->assertEquals('5', $matches[2]); // number
        $this->assertEquals('A', $matches[3]); // letter
        $this->assertEquals('', $matches[4]); // suffix
    }

    #[Test]
    public function it_matches_complex_prefix(): void
    {
        $stationNumber = 'STATION-100';

        preg_match(StationNumberUtil::REGEX_PATTERN, $stationNumber, $matches);

        $this->assertEquals('STATION-', $matches[1]); // prefix
        $this->assertEquals('100', $matches[2]); // number
        $this->assertEquals('', $matches[3]); // letter
        $this->assertEquals('', $matches[4]); // suffix
    }

    #[Test]
    public function it_matches_lowercase_letters(): void
    {
        $stationNumber = 'pc-5b';

        preg_match(StationNumberUtil::REGEX_PATTERN, $stationNumber, $matches);

        $this->assertEquals('pc-', $matches[1]); // prefix
        $this->assertEquals('5', $matches[2]); // number
        $this->assertEquals('b', $matches[3]); // letter (lowercase)
        $this->assertEquals('', $matches[4]); // suffix
    }

    #[Test]
    public function it_matches_multiple_digit_numbers(): void
    {
        $stationNumber = 'PC-9999Z';

        preg_match(StationNumberUtil::REGEX_PATTERN, $stationNumber, $matches);

        $this->assertEquals('PC-', $matches[1]); // prefix
        $this->assertEquals('9999', $matches[2]); // number
        $this->assertEquals('Z', $matches[3]); // letter
        $this->assertEquals('', $matches[4]); // suffix
    }

    #[Test]
    public function it_preserves_zero_padding_in_number(): void
    {
        $stationNumber = 'WS-0042A';

        preg_match(StationNumberUtil::REGEX_PATTERN, $stationNumber, $matches);

        $this->assertEquals('WS-', $matches[1]); // prefix
        $this->assertEquals('0042', $matches[2]); // number with padding
        $this->assertEquals('A', $matches[3]); // letter
        $this->assertEquals('', $matches[4]); // suffix
    }

    #[Test]
    public function it_handles_long_suffixes(): void
    {
        $stationNumber = 'ST-10A-MAIN-FLOOR-ROOM-B';

        preg_match(StationNumberUtil::REGEX_PATTERN, $stationNumber, $matches);

        $this->assertEquals('ST-', $matches[1]); // prefix
        $this->assertEquals('10', $matches[2]); // number
        $this->assertEquals('A', $matches[3]); // letter
        $this->assertEquals('-MAIN-FLOOR-ROOM-B', $matches[4]); // long suffix
    }

    #[Test]
    public function it_extracts_first_numeric_sequence(): void
    {
        // When multiple numbers exist, extracts the first numeric sequence
        $stationNumber = 'ROOM-5A-FLOOR2';

        preg_match(StationNumberUtil::REGEX_PATTERN, $stationNumber, $matches);

        $this->assertEquals('ROOM-', $matches[1]); // prefix
        $this->assertEquals('5', $matches[2]); // first number
        $this->assertEquals('A', $matches[3]); // letter
        $this->assertEquals('-FLOOR2', $matches[4]); // suffix (contains second number)
    }

    #[Test]
    public function it_handles_no_prefix_with_suffix(): void
    {
        $stationNumber = '100A-EXT';

        preg_match(StationNumberUtil::REGEX_PATTERN, $stationNumber, $matches);

        $this->assertEquals('', $matches[1]); // prefix (empty)
        $this->assertEquals('100', $matches[2]); // number
        $this->assertEquals('A', $matches[3]); // letter
        $this->assertEquals('-EXT', $matches[4]); // suffix
    }

    #[Test]
    public function it_validates_pattern_format(): void
    {
        // Verify the pattern is a valid regex
        $result = @preg_match(StationNumberUtil::REGEX_PATTERN, 'test');

        $this->assertNotFalse($result, 'Regex pattern should be valid');
    }

    #[Test]
    public function it_returns_false_for_non_numeric_strings(): void
    {
        $stationNumber = 'ABCDEF'; // No numbers

        $result = preg_match(StationNumberUtil::REGEX_PATTERN, $stationNumber, $matches);

        $this->assertEquals(0, $result); // No match
    }

    #[Test]
    public function it_matches_single_digit_stations(): void
    {
        $stationNumber = 'S-1';

        preg_match(StationNumberUtil::REGEX_PATTERN, $stationNumber, $matches);

        $this->assertEquals('S-', $matches[1]); // prefix
        $this->assertEquals('1', $matches[2]); // single digit
        $this->assertEquals('', $matches[3]); // letter
        $this->assertEquals('', $matches[4]); // suffix
    }

    #[Test]
    public function it_handles_special_characters_in_prefix(): void
    {
        $stationNumber = 'PC_WS-100';

        preg_match(StationNumberUtil::REGEX_PATTERN, $stationNumber, $matches);

        $this->assertEquals('PC_WS-', $matches[1]); // prefix with underscore
        $this->assertEquals('100', $matches[2]); // number
        $this->assertEquals('', $matches[3]); // letter
        $this->assertEquals('', $matches[4]); // suffix
    }

    #[Test]
    public function it_handles_special_characters_in_suffix(): void
    {
        $stationNumber = 'WS-10A@OFFICE';

        preg_match(StationNumberUtil::REGEX_PATTERN, $stationNumber, $matches);

        $this->assertEquals('WS-', $matches[1]); // prefix
        $this->assertEquals('10', $matches[2]); // number
        $this->assertEquals('A', $matches[3]); // letter
        $this->assertEquals('@OFFICE', $matches[4]); // suffix with special char
    }

    #[Test]
    public function it_extracts_components_for_bulk_generation(): void
    {
        // Test realistic scenario from StationController bulk generation
        $stationNumber = 'PC-001A';

        preg_match(StationNumberUtil::REGEX_PATTERN, $stationNumber, $matches);

        // Controller expects: prefix, number, letter, suffix
        $this->assertCount(5, $matches); // Full match + 4 groups

        $prefix = $matches[1];
        $numPart = (int) $matches[2];
        $letterPart = $matches[3] ?? '';
        $suffix = $matches[4] ?? '';
        $numLength = strlen($matches[2]);

        $this->assertEquals('PC-', $prefix);
        $this->assertEquals(1, $numPart);
        $this->assertEquals('A', $letterPart);
        $this->assertEquals('', $suffix);
        $this->assertEquals(3, $numLength); // For zero-padding
    }
}
