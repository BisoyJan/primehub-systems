<?php

namespace Tests\Feature\Controllers\FormRequests;

use App\Models\Campaign;
use App\Models\EmployeeSchedule;
use App\Models\LeaveCredit;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TeamLeadLeaveFilingTest extends TestCase
{
    use RefreshDatabase;

    protected User $teamLead;

    protected User $agent;

    protected Campaign $campaign;

    protected Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->site = Site::factory()->create();
        $this->campaign = Campaign::factory()->create();

        // Create Team Lead with active schedule in the campaign
        $this->teamLead = User::factory()->create([
            'role' => 'Team Lead',
            'is_approved' => true,
            'hired_date' => now()->subYears(2),
        ]);
        EmployeeSchedule::factory()->create([
            'user_id' => $this->teamLead->id,
            'site_id' => $this->site->id,
            'campaign_id' => $this->campaign->id,
            'is_active' => true,
        ]);

        // Create Agent in the same campaign
        $this->agent = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
            'hired_date' => now()->subYear(),
            'email' => 'agent@example.com',
        ]);
        EmployeeSchedule::factory()->create([
            'user_id' => $this->agent->id,
            'site_id' => $this->site->id,
            'campaign_id' => $this->campaign->id,
            'is_active' => true,
        ]);

        // Give agent leave credits
        LeaveCredit::create([
            'user_id' => $this->agent->id,
            'year' => now()->year,
            'month' => now()->month,
            'vacation_leave_balance' => 10,
            'sick_leave_balance' => 10,
            'credits_earned' => 1.25,
            'credits_balance' => 10,
            'accrued_at' => now(),
        ]);
    }

    #[Test]
    public function team_lead_can_access_create_page_with_employee_selector(): void
    {
        $response = $this->actingAs($this->teamLead)->get(route('leave-requests.create'));

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('FormRequest/Leave/Create')
                ->where('canFileForOthers', true)
                ->has('employees')
            );
    }

    #[Test]
    public function team_lead_sees_only_agents_in_their_campaign(): void
    {
        // Create agent in a different campaign
        $otherCampaign = Campaign::factory()->create();
        $otherAgent = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
            'hired_date' => now()->subYear(),
        ]);
        EmployeeSchedule::factory()->create([
            'user_id' => $otherAgent->id,
            'site_id' => $this->site->id,
            'campaign_id' => $otherCampaign->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->teamLead)->get(route('leave-requests.create'));

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('FormRequest/Leave/Create')
                ->where('canFileForOthers', true)
                ->has('employees', 1) // Only the agent in same campaign
                ->where('employees.0.id', $this->agent->id)
            );
    }

    #[Test]
    public function team_lead_can_load_create_form_for_agent_in_campaign(): void
    {
        $response = $this->actingAs($this->teamLead)->get(
            route('leave-requests.create', ['employee_id' => $this->agent->id])
        );

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('FormRequest/Leave/Create')
                ->where('selectedEmployeeId', $this->agent->id)
            );
    }

    #[Test]
    public function team_lead_cannot_load_create_form_for_agent_outside_campaign(): void
    {
        $otherCampaign = Campaign::factory()->create();
        $otherAgent = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
        ]);
        EmployeeSchedule::factory()->create([
            'user_id' => $otherAgent->id,
            'site_id' => $this->site->id,
            'campaign_id' => $otherCampaign->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->teamLead)->get(
            route('leave-requests.create', ['employee_id' => $otherAgent->id])
        );

        $response->assertStatus(403);
    }

    #[Test]
    public function team_lead_can_store_leave_for_agent_in_campaign(): void
    {
        $startDate = now()->addWeeks(3)->startOfWeek();
        $endDate = $startDate->copy()->addDay();

        $response = $this->actingAs($this->teamLead)->post(route('leave-requests.store'), [
            'employee_id' => $this->agent->id,
            'leave_type' => 'VL',
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
            'reason' => 'Team lead filing VL for agent in campaign',
            'campaign_department' => $this->campaign->name,
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('leave_requests', [
            'user_id' => $this->agent->id,
            'leave_type' => 'VL',
            'reason' => 'Team lead filing VL for agent in campaign',
            'status' => 'pending',
        ]);
    }

    #[Test]
    public function team_lead_cannot_store_leave_for_agent_outside_campaign(): void
    {
        $otherCampaign = Campaign::factory()->create();
        $otherAgent = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
            'hired_date' => now()->subYear(),
        ]);
        EmployeeSchedule::factory()->create([
            'user_id' => $otherAgent->id,
            'site_id' => $this->site->id,
            'campaign_id' => $otherCampaign->id,
            'is_active' => true,
        ]);

        $startDate = now()->addWeeks(3)->startOfWeek();
        $endDate = $startDate->copy()->addDay();

        $response = $this->actingAs($this->teamLead)->post(route('leave-requests.store'), [
            'employee_id' => $otherAgent->id,
            'leave_type' => 'VL',
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
            'reason' => 'Team lead trying to file for agent outside campaign',
            'campaign_department' => $otherCampaign->name,
        ]);

        $response->assertSessionHasErrors('error');

        $this->assertDatabaseMissing('leave_requests', [
            'user_id' => $otherAgent->id,
        ]);
    }

    #[Test]
    public function team_lead_cannot_file_leave_for_non_agent_role(): void
    {
        // Create an HR user in the same campaign
        $hrUser = User::factory()->create([
            'role' => 'HR',
            'is_approved' => true,
            'hired_date' => now()->subYear(),
        ]);
        EmployeeSchedule::factory()->create([
            'user_id' => $hrUser->id,
            'site_id' => $this->site->id,
            'campaign_id' => $this->campaign->id,
            'is_active' => true,
        ]);

        $startDate = now()->addWeeks(3)->startOfWeek();
        $endDate = $startDate->copy()->addDay();

        $response = $this->actingAs($this->teamLead)->post(route('leave-requests.store'), [
            'employee_id' => $hrUser->id,
            'leave_type' => 'VL',
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
            'reason' => 'Team lead trying to file for non-agent role',
            'campaign_department' => $this->campaign->name,
        ]);

        $response->assertSessionHasErrors('error');

        $this->assertDatabaseMissing('leave_requests', [
            'user_id' => $hrUser->id,
        ]);
    }

    #[Test]
    public function team_lead_cannot_override_short_notice(): void
    {
        $startDate = now()->addDays(3); // Less than 2 weeks
        // Ensure it's a weekday
        while ($startDate->isWeekend()) {
            $startDate->addDay();
        }
        $endDate = $startDate->copy();
        while ($endDate->isWeekend()) {
            $endDate->addDay();
        }

        $response = $this->actingAs($this->teamLead)->post(route('leave-requests.store'), [
            'employee_id' => $this->agent->id,
            'leave_type' => 'VL',
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
            'reason' => 'Short notice leave request test for team lead',
            'campaign_department' => $this->campaign->name,
            'short_notice_override' => true,
        ]);

        // Should fail validation since TL cannot override short notice
        $this->assertDatabaseMissing('leave_requests', [
            'user_id' => $this->agent->id,
            'short_notice_override' => true,
        ]);
    }

    #[Test]
    public function agent_does_not_see_employee_selector(): void
    {
        $response = $this->actingAs($this->agent)->get(route('leave-requests.create'));

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('FormRequest/Leave/Create')
                ->where('canFileForOthers', false)
                ->where('employees', [])
            );
    }
}
