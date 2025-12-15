<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\AttendancePoint;
use App\Models\User;
use App\Models\Attendance;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

class AttendancePointModelTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_has_fillable_attributes()
    {
        $point = new AttendancePoint();

        $expected = [
            'user_id',
            'attendance_id',
            'shift_date',
            'point_type',
            'points',
            'status',
            'is_advised',
            'notes',
            'is_excused',
            'is_manual',
            'created_by',
            'excused_by',
            'excused_at',
            'excuse_reason',
            'expires_at',
            'expiration_type',
            'is_expired',
            'expired_at',
            'violation_details',
            'tardy_minutes',
            'undertime_minutes',
            'eligible_for_gbro',
            'gbro_applied_at',
            'gbro_batch_id',
        ];

        $this->assertEquals($expected, $point->getFillable());
    }

    #[Test]
    public function it_casts_attributes_correctly()
    {
        $point = AttendancePoint::factory()->create([
            'shift_date' => '2025-11-01',
            'points' => 0.25,
            'is_advised' => true,
            'is_excused' => false,
            'excused_at' => now(),
            'expires_at' => '2025-05-01',
            'is_expired' => true,
            'expired_at' => '2025-05-01',
            'eligible_for_gbro' => true,
            'gbro_applied_at' => '2025-05-01',
        ]);

        $this->assertInstanceOf(Carbon::class, $point->shift_date);
        $this->assertIsString($point->points); // decimal:2 cast returns string
        $this->assertIsBool($point->is_advised);
        $this->assertIsBool($point->is_excused);
        $this->assertInstanceOf(Carbon::class, $point->excused_at);
        $this->assertInstanceOf(Carbon::class, $point->expires_at);
        $this->assertIsBool($point->is_expired);
        $this->assertInstanceOf(Carbon::class, $point->expired_at);
        $this->assertIsBool($point->eligible_for_gbro);
        $this->assertInstanceOf(Carbon::class, $point->gbro_applied_at);
    }

    #[Test]
    public function it_belongs_to_user()
    {
        $user = User::factory()->create();
        $point = AttendancePoint::factory()->create(['user_id' => $user->id]);

        $this->assertInstanceOf(User::class, $point->user);
        $this->assertEquals($user->id, $point->user->id);
    }

    #[Test]
    public function it_belongs_to_attendance()
    {
        $attendance = Attendance::factory()->create();
        $point = AttendancePoint::factory()->create(['attendance_id' => $attendance->id]);

        $this->assertInstanceOf(Attendance::class, $point->attendance);
        $this->assertEquals($attendance->id, $point->attendance->id);
    }

    #[Test]
    public function it_belongs_to_excused_by_user()
    {
        $supervisor = User::factory()->create();
        $point = AttendancePoint::factory()->excused($supervisor)->create();

        $this->assertInstanceOf(User::class, $point->excusedBy);
        $this->assertEquals($supervisor->id, $point->excusedBy->id);
    }

    #[Test]
    public function it_filters_active_points()
    {
        AttendancePoint::factory()->count(3)->create(['is_excused' => false]);
        AttendancePoint::factory()->count(2)->excused()->create();

        $activePoints = AttendancePoint::active()->get();

        $this->assertCount(3, $activePoints);
        $activePoints->each(function ($point) {
            $this->assertFalse($point->is_excused);
        });
    }

    #[Test]
    public function it_filters_by_date_range()
    {
        AttendancePoint::factory()->create(['shift_date' => '2025-11-01']);
        AttendancePoint::factory()->create(['shift_date' => '2025-11-15']);
        AttendancePoint::factory()->create(['shift_date' => '2025-11-30']);
        AttendancePoint::factory()->create(['shift_date' => '2025-12-05']);

        // dateRange scope uses whereBetween which is inclusive on both ends
        // So we need to test with a range that excludes the 4th record
        $points = AttendancePoint::dateRange('2025-11-01', '2025-11-29')->get();

        $this->assertCount(2, $points); // Only first two records
    }

    #[Test]
    public function it_filters_by_point_type()
    {
        AttendancePoint::factory()->tardy()->count(2)->create();
        AttendancePoint::factory()->undertime()->create();
        AttendancePoint::factory()->halfDayAbsence()->create();

        $tardyPoints = AttendancePoint::byType('tardy')->get();

        $this->assertCount(2, $tardyPoints);
        $tardyPoints->each(function ($point) {
            $this->assertEquals('tardy', $point->point_type);
        });
    }

    #[Test]
    public function it_filters_non_expired_points()
    {
        AttendancePoint::factory()->count(3)->create(['is_expired' => false]);
        AttendancePoint::factory()->count(2)->expiredSro()->create();

        $nonExpired = AttendancePoint::nonExpired()->get();

        $this->assertCount(3, $nonExpired);
        $nonExpired->each(function ($point) {
            $this->assertFalse($point->is_expired);
        });
    }

    #[Test]
    public function it_filters_expired_points()
    {
        AttendancePoint::factory()->count(2)->create(['is_expired' => false]);
        AttendancePoint::factory()->count(3)->expiredSro()->create();

        $expired = AttendancePoint::expired()->get();

        $this->assertCount(3, $expired);
        $expired->each(function ($point) {
            $this->assertTrue($point->is_expired);
        });
    }

    #[Test]
    public function it_filters_points_eligible_for_gbro()
    {
        // Eligible: not expired, not excused, eligible_for_gbro = true, no gbro_applied_at
        AttendancePoint::factory()->eligibleForGbro()->count(2)->create();

        // Not eligible: already expired
        AttendancePoint::factory()->eligibleForGbro()->expiredGbro()->create();

        // Not eligible: excused
        AttendancePoint::factory()->eligibleForGbro()->excused()->create();

        // Not eligible: NCNS (eligible_for_gbro = false)
        AttendancePoint::factory()->ncns()->create();

        $eligible = AttendancePoint::eligibleForGbro()->get();

        $this->assertCount(2, $eligible);
        $eligible->each(function ($point) {
            $this->assertTrue($point->eligible_for_gbro);
            $this->assertNull($point->gbro_applied_at);
            $this->assertFalse($point->is_expired);
            $this->assertFalse($point->is_excused);
        });
    }

    #[Test]
    public function it_detects_ncns_or_ftn()
    {
        $ncns = AttendancePoint::factory()->ncns()->create();
        $ftn = AttendancePoint::factory()->ftn()->create();
        $tardy = AttendancePoint::factory()->tardy()->create();

        $this->assertTrue($ncns->isNcnsOrFtn()); // is_advised = false
        // FTN has is_advised = true, so isNcnsOrFtn() returns false
        // (advised absences are treated differently than NCNS)
        $this->assertFalse($ftn->isNcnsOrFtn());
        $this->assertFalse($tardy->isNcnsOrFtn());
    }

    #[Test]
    public function it_calculates_expiration_date_for_standard_violations()
    {
        $shiftDate = Carbon::parse('2025-11-01');
        $point = AttendancePoint::factory()->tardy()->create(['shift_date' => $shiftDate]);

        $expectedExpiration = $shiftDate->copy()->addMonths(6);

        $this->assertEquals($expectedExpiration->format('Y-m-d'), $point->calculateExpirationDate()->format('Y-m-d'));
    }

    #[Test]
    public function it_calculates_expiration_date_for_ncns_ftn()
    {
        $shiftDate = Carbon::parse('2025-11-01');
        $point = AttendancePoint::factory()->ncns()->create(['shift_date' => $shiftDate]);

        $expectedExpiration = $shiftDate->copy()->addYear();

        $this->assertEquals($expectedExpiration->format('Y-m-d'), $point->calculateExpirationDate()->format('Y-m-d'));
    }

    #[Test]
    public function it_determines_if_point_should_expire()
    {
        // Point past expiration date
        $expiredPoint = AttendancePoint::factory()->pastExpiration()->create();
        $this->assertTrue($expiredPoint->shouldExpire());

        // Point not yet expired
        $activePoint = AttendancePoint::factory()->expiringSoon(30)->create();
        $this->assertFalse($activePoint->shouldExpire());

        // Already expired point
        $alreadyExpired = AttendancePoint::factory()->expiredSro()->create();
        $this->assertFalse($alreadyExpired->shouldExpire());

        // Excused point
        $excused = AttendancePoint::factory()->excused()->create();
        $this->assertFalse($excused->shouldExpire());
    }

    #[Test]
    public function it_marks_point_as_expired_with_sro()
    {
        $point = AttendancePoint::factory()->create([
            'is_expired' => false,
            'expired_at' => null,
            'expiration_type' => 'sro',
        ]);

        $point->markAsExpired('sro');

        $this->assertTrue($point->fresh()->is_expired);
        $this->assertNotNull($point->fresh()->expired_at);
        $this->assertEquals('sro', $point->fresh()->expiration_type);
    }

    #[Test]
    public function it_marks_point_as_expired_with_gbro()
    {
        $point = AttendancePoint::factory()->create([
            'is_expired' => false,
            'expired_at' => null,
        ]);

        $point->markAsExpired('gbro');

        $this->assertTrue($point->fresh()->is_expired);
        $this->assertNotNull($point->fresh()->expired_at);
        $this->assertEquals('gbro', $point->fresh()->expiration_type);
    }

    #[Test]
    public function it_returns_formatted_type_name()
    {
        $tardyPoint = AttendancePoint::factory()->tardy()->make();
        $this->assertEquals('Tardy', $tardyPoint->formatted_type);

        $ncnsPoint = AttendancePoint::factory()->ncns()->make();
        $this->assertEquals('Whole Day Absence (NCNS)', $ncnsPoint->formatted_type);

        $halfDayPoint = AttendancePoint::factory()->halfDayAbsence()->make();
        $this->assertEquals('Half-Day Absence', $halfDayPoint->formatted_type);

        $undertimePoint = AttendancePoint::factory()->undertime()->make();
        $this->assertEquals('Undertime', $undertimePoint->formatted_type);
    }

    #[Test]
    public function it_returns_type_color_for_badge()
    {
        $this->assertEquals('red', AttendancePoint::factory()->ncns()->make()->type_color);
        $this->assertEquals('orange', AttendancePoint::factory()->halfDayAbsence()->make()->type_color);
        $this->assertEquals('yellow', AttendancePoint::factory()->tardy()->make()->type_color);
        $this->assertEquals('yellow', AttendancePoint::factory()->undertime()->make()->type_color);
    }

    #[Test]
    public function it_generates_violation_details_text()
    {
        $tardyPoint = AttendancePoint::factory()->tardy(12)->create();
        $this->assertStringContainsString('Tardy', $tardyPoint->violation_details);
        $this->assertStringContainsString('12 minutes', $tardyPoint->violation_details);

        $undertimePoint = AttendancePoint::factory()->undertime(90)->create();
        $this->assertStringContainsString('Undertime', $undertimePoint->violation_details);
        $this->assertStringContainsString('90 minutes', $undertimePoint->violation_details);

        $ncnsPoint = AttendancePoint::factory()->ncns()->create();
        $this->assertStringContainsString('NCNS', $ncnsPoint->violation_details);
    }

    #[Test]
    public function it_returns_expiration_status_message()
    {
        // Active point expiring soon
        $activeSoon = AttendancePoint::factory()->expiringSoon(15)->create();
        $status = $activeSoon->expiration_status;
        $this->assertStringContainsString('Expires in', $status);
        $this->assertStringContainsString('days', $status);

        // Expired via SRO
        $expiredSro = AttendancePoint::factory()->expiredSro()->create();
        $this->assertStringContainsString('Expired via SRO', $expiredSro->expiration_status);

        // Expired via GBRO
        $expiredGbro = AttendancePoint::factory()->expiredGbro()->create();
        $this->assertStringContainsString('Expired via GBRO', $expiredGbro->expiration_status);
        $this->assertStringContainsString('Good Behavior', $expiredGbro->expiration_status);

        // Point past expiration (pending)
        $pastExpiration = AttendancePoint::factory()->pastExpiration()->create();
        $this->assertStringContainsString('Pending expiration', $pastExpiration->expiration_status);
    }

    #[Test]
    public function point_values_constant_is_defined()
    {
        $expected = [
            'whole_day_absence' => 1.00,
            'half_day_absence' => 0.50,
            'undertime' => 0.25,
            'undertime_more_than_hour' => 0.50,
            'tardy' => 0.25,
        ];

        $this->assertEquals($expected, AttendancePoint::POINT_VALUES);
    }

    #[Test]
    public function it_has_correct_point_values_for_each_type()
    {
        $tardy = AttendancePoint::factory()->tardy()->create();
        $this->assertEquals(0.25, $tardy->points);

        $undertime = AttendancePoint::factory()->undertime()->create();
        $this->assertEquals(0.25, $undertime->points);

        $undertimeMoreThanHour = AttendancePoint::factory()->undertimeMoreThanHour()->create();
        $this->assertEquals(0.50, $undertimeMoreThanHour->points);

        $halfDay = AttendancePoint::factory()->halfDayAbsence()->create();
        $this->assertEquals(0.50, $halfDay->points);

        $ncns = AttendancePoint::factory()->ncns()->create();
        $this->assertEquals(1.00, $ncns->points);
    }

    #[Test]
    public function ncns_and_ftn_are_not_eligible_for_gbro()
    {
        $ncns = AttendancePoint::factory()->ncns()->create();
        $this->assertFalse($ncns->eligible_for_gbro);

        $ftn = AttendancePoint::factory()->ftn()->create();
        $this->assertFalse($ftn->eligible_for_gbro);
    }

    #[Test]
    public function standard_violations_are_eligible_for_gbro()
    {
        $tardy = AttendancePoint::factory()->tardy()->create();
        $this->assertTrue($tardy->eligible_for_gbro);

        $undertime = AttendancePoint::factory()->undertime()->create();
        $this->assertTrue($undertime->eligible_for_gbro);

        $halfDay = AttendancePoint::factory()->halfDayAbsence()->create();
        $this->assertTrue($halfDay->eligible_for_gbro);
    }

    #[Test]
    public function ncns_and_ftn_expire_after_one_year()
    {
        $shiftDate = Carbon::parse('2025-11-01');
        $ncns = AttendancePoint::factory()->ncns()->create(['shift_date' => $shiftDate]);

        // Use calculateExpirationDate to test the logic, not the factory-set expires_at
        $expectedExpiration = $shiftDate->copy()->addYear();
        $this->assertEquals($expectedExpiration->format('Y-m-d'), $ncns->calculateExpirationDate()->format('Y-m-d'));
    }

    #[Test]
    public function standard_violations_expire_after_six_months()
    {
        $shiftDate = Carbon::parse('2025-11-01');
        $tardy = AttendancePoint::factory()->tardy()->create(['shift_date' => $shiftDate]);

        // Use calculateExpirationDate to test the logic, not the factory-set expires_at
        $expectedExpiration = $shiftDate->copy()->addMonths(6);
        $this->assertEquals($expectedExpiration->format('Y-m-d'), $tardy->calculateExpirationDate()->format('Y-m-d'));
    }
}

