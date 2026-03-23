<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\EmployeeSchedule;
use App\Models\LeaveRequest;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LeaveRequestStatusTabsTest extends TestCase
{
    use RefreshDatabase;

    private function createAdmin(): User
    {
        return User::factory()->create([
            'role' => 'Admin',
            'is_approved' => true,
        ]);
    }

    private function createAgent(): User
    {
        $user = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
        ]);

        $site = Site::factory()->create();
        $campaign = Campaign::factory()->create();
        EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
            'site_id' => $site->id,
            'campaign_id' => $campaign->id,
            'is_active' => true,
        ]);

        return $user;
    }

    #[Test]
    public function admin_index_returns_status_counts(): void
    {
        $admin = $this->createAdmin();
        $agent = $this->createAgent();

        LeaveRequest::factory()->count(3)->pending()->create(['user_id' => $agent->id]);
        LeaveRequest::factory()->count(2)->approved()->create(['user_id' => $agent->id]);
        LeaveRequest::factory()->count(1)->denied()->create(['user_id' => $agent->id]);
        LeaveRequest::factory()->count(1)->cancelled()->create(['user_id' => $agent->id]);

        $response = $this->actingAs($admin)->get(route('leave-requests.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('FormRequest/Leave/Index')
            ->has('statusCounts')
            ->where('statusCounts.all', 7)
            ->where('statusCounts.pending', 3)
            ->where('statusCounts.approved', 2)
            ->where('statusCounts.denied', 1)
            ->where('statusCounts.cancelled', 1)
        );
    }

    #[Test]
    public function status_filter_returns_only_matching_requests(): void
    {
        $admin = $this->createAdmin();
        $agent = $this->createAgent();

        LeaveRequest::factory()->count(3)->pending()->create(['user_id' => $agent->id]);
        LeaveRequest::factory()->count(2)->approved()->create(['user_id' => $agent->id]);

        $response = $this->actingAs($admin)->get(route('leave-requests.index', ['status' => 'pending']));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('FormRequest/Leave/Index')
            ->has('leaveRequests.data', 3)
            ->where('statusCounts.all', 5)
            ->where('statusCounts.pending', 3)
            ->where('statusCounts.approved', 2)
        );
    }

    #[Test]
    public function agent_sees_only_own_status_counts(): void
    {
        $agent = $this->createAgent();
        $otherAgent = $this->createAgent();

        LeaveRequest::factory()->count(2)->pending()->create(['user_id' => $agent->id]);
        LeaveRequest::factory()->count(1)->approved()->create(['user_id' => $agent->id]);
        LeaveRequest::factory()->count(3)->pending()->create(['user_id' => $otherAgent->id]);

        $response = $this->actingAs($agent)->get(route('leave-requests.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('FormRequest/Leave/Index')
            ->where('statusCounts.all', 3)
            ->where('statusCounts.pending', 2)
            ->where('statusCounts.approved', 1)
        );
    }

    #[Test]
    public function status_counts_respect_other_filters(): void
    {
        $admin = $this->createAdmin();
        $agent = $this->createAgent();

        LeaveRequest::factory()->count(2)->pending()->create([
            'user_id' => $agent->id,
            'leave_type' => 'VL',
        ]);
        LeaveRequest::factory()->count(1)->pending()->create([
            'user_id' => $agent->id,
            'leave_type' => 'SL',
        ]);
        LeaveRequest::factory()->count(1)->approved()->create([
            'user_id' => $agent->id,
            'leave_type' => 'VL',
        ]);

        $response = $this->actingAs($admin)->get(route('leave-requests.index', ['type' => 'VL']));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('FormRequest/Leave/Index')
            ->where('statusCounts.all', 3)
            ->where('statusCounts.pending', 2)
            ->where('statusCounts.approved', 1)
        );
    }

    #[Test]
    public function period_filter_scopes_results_and_counts(): void
    {
        $admin = $this->createAdmin();
        $agent = $this->createAgent();

        // Leave in the past
        LeaveRequest::factory()->pending()->create([
            'user_id' => $agent->id,
            'start_date' => '2025-01-10',
            'end_date' => '2025-01-12',
        ]);

        // Leave in the future (upcoming)
        LeaveRequest::factory()->approved()->create([
            'user_id' => $agent->id,
            'start_date' => '2027-06-01',
            'end_date' => '2027-06-05',
        ]);
        LeaveRequest::factory()->pending()->create([
            'user_id' => $agent->id,
            'start_date' => '2027-07-15',
            'end_date' => '2027-07-17',
        ]);

        // Filter to upcoming only
        $response = $this->actingAs($admin)->get(route('leave-requests.index', [
            'period' => 'upcoming',
        ]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('FormRequest/Leave/Index')
            ->has('leaveRequests.data', 2)
            ->where('statusCounts.all', 2)
            ->where('statusCounts.pending', 1)
            ->where('statusCounts.approved', 1)
        );
    }

    #[Test]
    public function past_period_filter_returns_only_past_leaves(): void
    {
        $admin = $this->createAdmin();
        $agent = $this->createAgent();

        // Leave in the past
        LeaveRequest::factory()->approved()->create([
            'user_id' => $agent->id,
            'start_date' => '2025-01-10',
            'end_date' => '2025-01-12',
        ]);
        LeaveRequest::factory()->denied()->create([
            'user_id' => $agent->id,
            'start_date' => '2025-06-01',
            'end_date' => '2025-06-03',
        ]);

        // Leave in the future
        LeaveRequest::factory()->pending()->create([
            'user_id' => $agent->id,
            'start_date' => '2027-12-01',
            'end_date' => '2027-12-05',
        ]);

        $response = $this->actingAs($admin)->get(route('leave-requests.index', [
            'period' => 'past',
        ]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('FormRequest/Leave/Index')
            ->has('leaveRequests.data', 2)
            ->where('statusCounts.all', 2)
            ->where('statusCounts.approved', 1)
            ->where('statusCounts.denied', 1)
            ->where('statusCounts.pending', 0)
        );
    }
}
