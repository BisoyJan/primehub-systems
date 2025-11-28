<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\EmployeeSchedule;
use App\Models\User;
use App\Services\AttendanceFileParser;
use App\Services\AttendanceProcessor;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AttendanceProcessorTest extends TestCase
{
    use RefreshDatabase;

    private AttendanceProcessor $processor;
    private AttendanceFileParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new AttendanceFileParser();
        $this->processor = new AttendanceProcessor($this->parser);
    }

    /**
     * Helper method to call protected methods using reflection
     */
    private function callProtectedMethod($object, string $method, array $args = [])
    {
        $reflection = new \ReflectionClass($object);
        $method = $reflection->getMethod($method);
        $method->setAccessible(true);
        return $method->invokeArgs($object, $args);
    }

    #[Test]
    public function it_determines_shift_type_for_morning_shift(): void
    {
        $schedule = EmployeeSchedule::factory()->create([
            'scheduled_time_in' => '08:00:00',
            'scheduled_time_out' => '17:00:00',
        ]);

        $shiftType = $this->callProtectedMethod($this->processor, 'determineShiftType', [$schedule]);

        $this->assertEquals('morning', $shiftType);
    }

    #[Test]
    public function it_determines_shift_type_for_afternoon_shift(): void
    {
        $schedule = EmployeeSchedule::factory()->create([
            'scheduled_time_in' => '14:00:00',
            'scheduled_time_out' => '23:00:00',
        ]);

        $shiftType = $this->callProtectedMethod($this->processor, 'determineShiftType', [$schedule]);

        $this->assertEquals('afternoon', $shiftType);
    }

    #[Test]
    public function it_determines_shift_type_for_evening_shift(): void
    {
        $schedule = EmployeeSchedule::factory()->create([
            'scheduled_time_in' => '18:00:00',
            'scheduled_time_out' => '03:00:00', // Next day
        ]);

        $shiftType = $this->callProtectedMethod($this->processor, 'determineShiftType', [$schedule]);

        $this->assertEquals('evening', $shiftType);
    }

    #[Test]
    public function it_determines_shift_type_for_night_shift(): void
    {
        $schedule = EmployeeSchedule::factory()->create([
            'scheduled_time_in' => '22:00:00',
            'scheduled_time_out' => '07:00:00', // Next day
        ]);

        $shiftType = $this->callProtectedMethod($this->processor, 'determineShiftType', [$schedule]);

        $this->assertEquals('night', $shiftType);
    }

    #[Test]
    public function it_determines_shift_type_for_graveyard_shift(): void
    {
        $schedule = EmployeeSchedule::factory()->create([
            'scheduled_time_in' => '00:00:00', // Midnight
            'scheduled_time_out' => '09:00:00',
        ]);

        $shiftType = $this->callProtectedMethod($this->processor, 'determineShiftType', [$schedule]);

        $this->assertEquals('graveyard', $shiftType);
    }

    #[Test]
    public function it_determines_next_day_shift(): void
    {
        $schedule = EmployeeSchedule::factory()->create([
            'scheduled_time_in' => '22:00:00',
            'scheduled_time_out' => '07:00:00', // Out time < in time = next day
        ]);

        $isNextDay = $this->callProtectedMethod($this->processor, 'isNextDayShift', [$schedule]);

        $this->assertTrue($isNextDay);
    }

    #[Test]
    public function it_determines_same_day_shift(): void
    {
        $schedule = EmployeeSchedule::factory()->create([
            'scheduled_time_in' => '08:00:00',
            'scheduled_time_out' => '17:00:00', // Out time > in time = same day
        ]);

        $isNextDay = $this->callProtectedMethod($this->processor, 'isNextDayShift', [$schedule]);

        $this->assertFalse($isNextDay);
    }

    #[Test]
    public function it_handles_graveyard_same_day_shift(): void
    {
        // 00:00-09:00 is same day (midnight to morning)
        $schedule = EmployeeSchedule::factory()->create([
            'scheduled_time_in' => '00:00:00',
            'scheduled_time_out' => '09:00:00',
        ]);

        $isNextDay = $this->callProtectedMethod($this->processor, 'isNextDayShift', [$schedule]);

        $this->assertFalse($isNextDay); // Same day
    }

    #[Test]
    public function it_determines_time_in_status_on_time(): void
    {
        $tardyMinutes = 5; // 5 minutes late
        $gracePeriod = 15;

        $status = $this->callProtectedMethod($this->processor, 'determineTimeInStatus', [$tardyMinutes, $gracePeriod]);

        $this->assertEquals('on_time', $status);
    }

    #[Test]
    public function it_determines_time_in_status_tardy(): void
    {
        $tardyMinutes = 15; // Exactly 15 minutes late (at threshold)
        $gracePeriod = 15;

        $status = $this->callProtectedMethod($this->processor, 'determineTimeInStatus', [$tardyMinutes, $gracePeriod]);

        $this->assertEquals('tardy', $status);
    }

    #[Test]
    public function it_determines_time_in_status_half_day_absence(): void
    {
        $tardyMinutes = 45; // 45 minutes late (exceeds grace period)
        $gracePeriod = 15;

        $status = $this->callProtectedMethod($this->processor, 'determineTimeInStatus', [$tardyMinutes, $gracePeriod]);

        $this->assertEquals('half_day_absence', $status);
    }

    #[Test]
    public function it_finds_user_by_name_with_single_match(): void
    {
        User::factory()->create([
            'first_name' => 'John',
            'last_name' => 'Rosel',
        ]);

        $user = $this->callProtectedMethod($this->processor, 'findUserByName', ['rosel', null]);

        $this->assertNotNull($user);
        $this->assertEquals('Rosel', $user->last_name);
    }

    #[Test]
    public function it_finds_user_by_name_with_initial(): void
    {
        User::factory()->create([
            'first_name' => 'Alice',
            'last_name' => 'Cabarliza',
        ]);

        $user = $this->callProtectedMethod($this->processor, 'findUserByName', ['cabarliza a', null]);

        $this->assertNotNull($user);
        $this->assertEquals('Alice', $user->first_name);
    }

    #[Test]
    public function it_finds_user_by_name_with_two_letters(): void
    {
        User::factory()->create([
            'first_name' => 'Jennifer',
            'last_name' => 'Robinios',
        ]);

        $user = $this->callProtectedMethod($this->processor, 'findUserByName', ['robinios je', null]);

        $this->assertNotNull($user);
        $this->assertEquals('Jennifer', $user->first_name);
    }

    #[Test]
    public function it_returns_null_for_nonexistent_user(): void
    {
        $user = $this->callProtectedMethod($this->processor, 'findUserByName', ['nonexistent', null]);

        $this->assertNull($user);
    }

    #[Test]
    public function it_maps_ncns_status_to_point_type(): void
    {
        $pointType = $this->callProtectedMethod($this->processor, 'mapStatusToPointType', ['ncns']);

        $this->assertEquals('whole_day_absence', $pointType);
    }

    #[Test]
    public function it_maps_tardy_status_to_point_type(): void
    {
        $pointType = $this->callProtectedMethod($this->processor, 'mapStatusToPointType', ['tardy']);

        $this->assertEquals('tardy', $pointType);
    }

    #[Test]
    public function it_maps_half_day_absence_status_to_point_type(): void
    {
        $pointType = $this->callProtectedMethod($this->processor, 'mapStatusToPointType', ['half_day_absence']);

        $this->assertEquals('half_day_absence', $pointType);
    }

    #[Test]
    public function it_maps_undertime_status_to_point_type(): void
    {
        $pointType = $this->callProtectedMethod($this->processor, 'mapStatusToPointType', ['undertime']);

        $this->assertEquals('undertime', $pointType);
    }
}


