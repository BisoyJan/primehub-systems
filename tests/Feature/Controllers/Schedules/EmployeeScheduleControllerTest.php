<?php

namespace Tests\Feature\Controllers\Schedules;

use App\Models\Campaign;
use App\Models\EmployeeSchedule;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EmployeeScheduleControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected User $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create([
            'role' => 'Admin',
            'is_approved' => true,
        ]);
        $this->employee = User::factory()->create([
            'first_name' => 'John',
            'last_name' => 'Employee',
            'is_approved' => true,
        ]);
    }

    public function test_index_displays_employee_schedules(): void
    {
        EmployeeSchedule::factory()->count(5)->create();

        $response = $this->actingAs($this->user)
            ->get(route('employee-schedules.index'));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Attendance/EmployeeSchedules/Index')
                ->has('schedules')
                ->has('users')
                ->has('campaigns')
                ->has('sites')
            );
    }

    public function test_index_searches_by_employee_name(): void
    {
        EmployeeSchedule::factory()->create(['user_id' => $this->employee->id]);
        EmployeeSchedule::factory()->count(2)->create();

        $response = $this->actingAs($this->user)
            ->get(route('employee-schedules.index', ['search' => 'John']));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Attendance/EmployeeSchedules/Index')
                ->where('filters.search', 'John')
            );
    }

    public function test_index_filters_by_user_id(): void
    {
        EmployeeSchedule::factory()->create(['user_id' => $this->employee->id]);
        EmployeeSchedule::factory()->count(2)->create();

        $response = $this->actingAs($this->user)
            ->get(route('employee-schedules.index', ['user_id' => $this->employee->id]));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.user_id', (string) $this->employee->id)
            );
    }

    public function test_index_filters_by_campaign_id(): void
    {
        $campaign = Campaign::factory()->create();
        EmployeeSchedule::factory()->create(['campaign_id' => $campaign->id]);
        EmployeeSchedule::factory()->count(2)->create();

        $response = $this->actingAs($this->user)
            ->get(route('employee-schedules.index', ['campaign_id' => $campaign->id]));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.campaign_id', (string) $campaign->id)
            );
    }

    public function test_index_filters_by_active_status(): void
    {
        EmployeeSchedule::factory()->create(['is_active' => true]);
        EmployeeSchedule::factory()->create(['is_active' => false]);

        $response = $this->actingAs($this->user)
            ->get(route('employee-schedules.index', ['is_active' => true]));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.is_active', '1')
            );
    }

    public function test_create_displays_create_form(): void
    {
        $response = $this->actingAs($this->user)
            ->get(route('employee-schedules.create'));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Attendance/EmployeeSchedules/Create')
                ->has('users')
                ->has('campaigns')
                ->has('sites')
            );
    }

    public function test_store_creates_employee_schedule(): void
    {
        $campaign = Campaign::factory()->create();
        $site = Site::factory()->create();

        $scheduleData = [
            'user_id' => $this->employee->id,
            'campaign_id' => $campaign->id,
            'site_id' => $site->id,
            'scheduled_time_in' => '08:00',
            'scheduled_time_out' => '17:00',
            'work_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
            'grace_period_minutes' => 15,
            'effective_date' => '2024-01-01',
        ];

        $response = $this->actingAs($this->user)
            ->post(route('employee-schedules.store'), $scheduleData);

        $response->assertRedirect(route('employee-schedules.index'))
            ->assertSessionHas('type', 'success');

        $this->assertDatabaseHas('employee_schedules', [
            'user_id' => $this->employee->id,
            'campaign_id' => $campaign->id,
            'shift_type' => 'morning_shift',
        ]);
    }

    public function test_store_deactivates_previous_active_schedules(): void
    {
        $previousSchedule = EmployeeSchedule::factory()->create([
            'user_id' => $this->employee->id,
            'is_active' => true,
        ]);

        $scheduleData = [
            'user_id' => $this->employee->id,
            'scheduled_time_in' => '14:00',
            'scheduled_time_out' => '23:00',
            'work_days' => ['monday', 'tuesday', 'wednesday'],
            'grace_period_minutes' => 10,
            'effective_date' => '2024-02-01',
        ];

        $response = $this->actingAs($this->user)
            ->post(route('employee-schedules.store'), $scheduleData);

        $response->assertRedirect();

        $previousSchedule->refresh();
        $this->assertFalse($previousSchedule->is_active);
    }

    public function test_store_validates_required_fields(): void
    {
        $response = $this->actingAs($this->user)
            ->post(route('employee-schedules.store'), []);

        $response->assertSessionHasErrors([
            'user_id',
            'scheduled_time_in',
            'scheduled_time_out',
            'work_days',
            'grace_period_minutes',
            'effective_date',
        ]);
    }

    public function test_edit_displays_edit_form(): void
    {
        $schedule = EmployeeSchedule::factory()->create();

        $response = $this->actingAs($this->user)
            ->get(route('employee-schedules.edit', $schedule));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Attendance/EmployeeSchedules/Edit')
                ->has('schedule')
                ->where('schedule.id', $schedule->id)
            );
    }

    public function test_update_modifies_employee_schedule(): void
    {
        $schedule = EmployeeSchedule::factory()->create([
            'shift_type' => 'morning_shift',
            'grace_period_minutes' => 10,
        ]);

        $updateData = [
            'scheduled_time_in' => '22:00',
            'scheduled_time_out' => '06:00',
            'work_days' => ['sunday', 'monday', 'tuesday', 'wednesday'],
            'grace_period_minutes' => 20,
        ];

        $response = $this->actingAs($this->user)
            ->put(route('employee-schedules.update', $schedule), $updateData);

        $response->assertRedirect(route('employee-schedules.index'))
            ->assertSessionHas('type', 'success');

        $this->assertDatabaseHas('employee_schedules', [
            'id' => $schedule->id,
            'shift_type' => 'night_shift',
            'grace_period_minutes' => 20,
        ]);
    }

    public function test_destroy_deletes_employee_schedule(): void
    {
        $schedule = EmployeeSchedule::factory()->create();

        $response = $this->actingAs($this->user)
            ->delete(route('employee-schedules.destroy', $schedule));

        $response->assertRedirect(route('employee-schedules.index'))
            ->assertSessionHas('type', 'success');

        $this->assertDatabaseMissing('employee_schedules', ['id' => $schedule->id]);
    }

    public function test_toggle_active_activates_inactive_schedule(): void
    {
        $schedule = EmployeeSchedule::factory()->create([
            'user_id' => $this->employee->id,
            'is_active' => false,
        ]);

        $response = $this->actingAs($this->user)
            ->post(route('employee-schedules.toggleActive', $schedule));

        $response->assertRedirect()
            ->assertSessionHas('type', 'success');

        $schedule->refresh();
        $this->assertTrue($schedule->is_active);
    }

    public function test_toggle_active_deactivates_active_schedule(): void
    {
        $schedule = EmployeeSchedule::factory()->create([
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user)
            ->post(route('employee-schedules.toggleActive', $schedule));

        $response->assertRedirect();

        $schedule->refresh();
        $this->assertFalse($schedule->is_active);
    }

    public function test_toggle_active_deactivates_other_schedules_when_activating(): void
    {
        $activeSchedule = EmployeeSchedule::factory()->create([
            'user_id' => $this->employee->id,
            'is_active' => true,
        ]);

        $inactiveSchedule = EmployeeSchedule::factory()->create([
            'user_id' => $this->employee->id,
            'is_active' => false,
        ]);

        $response = $this->actingAs($this->user)
            ->post(route('employee-schedules.toggleActive', $inactiveSchedule));

        $response->assertRedirect();

        $activeSchedule->refresh();
        $inactiveSchedule->refresh();

        $this->assertFalse($activeSchedule->is_active);
        $this->assertTrue($inactiveSchedule->is_active);
    }

    public function test_get_schedule_returns_schedule_for_user_and_date(): void
    {
        $schedule = EmployeeSchedule::factory()->create([
            'user_id' => $this->employee->id,
            'is_active' => true,
            'effective_date' => '2024-01-01',
            'end_date' => null,
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('employee-schedules.getSchedule', [
                'user_id' => $this->employee->id,
                'date' => '2024-06-15',
            ]));

        $response->assertStatus(200)
            ->assertJson(['id' => $schedule->id]);
    }

    public function test_get_schedule_validates_required_parameters(): void
    {
        $response = $this->actingAs($this->user)
            ->get(route('employee-schedules.getSchedule'));

        $response->assertSessionHasErrors(['user_id', 'date']);
    }

    public function test_index_hides_resigned_employees_by_default(): void
    {
        // Create a resigned employee (has hired_date but is_active = false)
        $resignedEmployee = User::factory()->create([
            'hired_date' => now()->subMonths(6),
            'is_active' => false,
            'is_approved' => true,
        ]);
        $resignedSchedule = EmployeeSchedule::factory()->create(['user_id' => $resignedEmployee->id]);

        // Create an active employee
        $activeEmployee = User::factory()->create([
            'hired_date' => now()->subMonths(3),
            'is_active' => true,
            'is_approved' => true,
        ]);
        $activeSchedule = EmployeeSchedule::factory()->create(['user_id' => $activeEmployee->id]);

        $response = $this->actingAs($this->user)
            ->get(route('employee-schedules.index'));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Attendance/EmployeeSchedules/Index')
                ->has('schedules.data', 1) // Only 1 schedule (the active employee)
                ->where('schedules.data.0.user.id', $activeEmployee->id)
            );
    }

    public function test_index_shows_resigned_employees_when_filter_enabled(): void
    {
        // Create a resigned employee (has hired_date but is_active = false)
        $resignedEmployee = User::factory()->create([
            'hired_date' => now()->subMonths(6),
            'is_active' => false,
            'is_approved' => true,
        ]);
        $resignedSchedule = EmployeeSchedule::factory()->create(['user_id' => $resignedEmployee->id]);

        // Create an active employee
        $activeEmployee = User::factory()->create([
            'hired_date' => now()->subMonths(3),
            'is_active' => true,
            'is_approved' => true,
        ]);
        $activeSchedule = EmployeeSchedule::factory()->create(['user_id' => $activeEmployee->id]);

        $response = $this->actingAs($this->user)
            ->get(route('employee-schedules.index', ['show_resigned' => true]));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Attendance/EmployeeSchedules/Index')
                ->has('schedules.data', 2) // Both schedules should be visible
                ->where('filters.show_resigned', '1')
            );
    }

    public function test_index_shows_employees_without_hired_date(): void
    {
        // Create an employee without hired_date (not yet hired)
        $notHiredEmployee = User::factory()->create([
            'hired_date' => null,
            'is_active' => false,
            'is_approved' => true,
        ]);
        $notHiredSchedule = EmployeeSchedule::factory()->create(['user_id' => $notHiredEmployee->id]);

        $response = $this->actingAs($this->user)
            ->get(route('employee-schedules.index'));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Attendance/EmployeeSchedules/Index')
                ->has('schedules.data', 1) // Should be visible (no hired_date)
                ->where('schedules.data.0.user.id', $notHiredEmployee->id)
            );
    }

    /**
     * @dataProvider shiftDerivationProvider
     */
    #[DataProvider('shiftDerivationProvider')]
    public function test_store_derives_shift_type_from_time_in(string $timeIn, bool $isUtility, string $expectedShiftType): void
    {
        $campaign = Campaign::factory()->create();
        $site = Site::factory()->create();

        $payload = [
            'user_id' => $this->employee->id,
            'campaign_id' => $campaign->id,
            'site_id' => $site->id,
            'is_utility' => $isUtility,
            'scheduled_time_in' => $timeIn,
            'scheduled_time_out' => '17:00',
            'work_days' => ['monday'],
            'grace_period_minutes' => 0,
            'effective_date' => '2024-01-01',
        ];

        $this->actingAs($this->user)
            ->post(route('employee-schedules.store'), $payload)
            ->assertRedirect(route('employee-schedules.index'));

        $this->assertDatabaseHas('employee_schedules', [
            'user_id' => $this->employee->id,
            'shift_type' => $expectedShiftType,
            'scheduled_time_in' => $timeIn.':00',
        ]);
    }

    public static function shiftDerivationProvider(): array
    {
        return [
            'graveyard window maps to night_shift' => ['02:00', false, 'night_shift'],
            'morning window' => ['06:00', false, 'morning_shift'],
            'afternoon window' => ['14:00', false, 'afternoon_shift'],
            'late-night window maps to night_shift' => ['22:00', false, 'night_shift'],
            'is_utility flag overrides time-based derivation' => ['09:00', true, 'utility_24h'],
        ];
    }
}
