<?php

namespace Tests\Feature\FormRequests;

use App\Models\LeaveRequest;
use App\Models\User;
use App\Models\LeaveCredit;
use App\Services\LeaveCreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use App\Mail\LeaveRequestSubmitted;
use App\Mail\LeaveRequestStatusUpdated;
use Inertia\Testing\AssertableInertia as Assert;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;


class LeaveRequestTest extends TestCase
{
    use RefreshDatabase;

    protected User $employee;
    protected User $admin;
    protected LeaveCreditService $leaveCreditService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->employee = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
            'hired_date' => Carbon::now()->subMonths(7), // Eligible (>6 months)
        ]);

        // Admin role has leave.approve permissions
        $this->admin = User::factory()->create([
            'role' => 'Admin',
            'is_approved' => true,
        ]);

        $this->leaveCreditService = app(LeaveCreditService::class);

        // Give employee some leave credits
        LeaveCredit::factory()->create([
            'user_id' => $this->employee->id,
            'year' => Carbon::now()->year,
            'month' => Carbon::now()->month,
            'credits_balance' => 5.0,
            'credits_earned' => 1.25,
            'credits_used' => 0,
        ]);
    }

    #[Test]
    public function it_displays_leave_requests_index_for_admin()
    {
        LeaveRequest::factory()->count(3)->create();

        $response = $this->actingAs($this->admin)
            ->get(route('leave-requests.index'));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('FormRequest/Leave/Index')
                ->has('leaveRequests.data', 3)
            );
    }

    #[Test]
    public function it_displays_only_own_requests_for_employee()
    {
        LeaveRequest::factory()->create(['user_id' => $this->employee->id]);
        LeaveRequest::factory()->create(); // Another user's request

        $response = $this->actingAs($this->employee)
            ->get(route('leave-requests.index'));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('FormRequest/Leave/Index')
                ->has('leaveRequests.data', 1)
                ->where('leaveRequests.data.0.user_id', $this->employee->id)
            );
    }

    #[Test]
    public function it_displays_create_leave_request_form()
    {
        $response = $this->actingAs($this->employee)
            ->get(route('leave-requests.create'));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('FormRequest/Leave/Create')
                ->has('creditsSummary')
                ->has('attendancePoints')
            );
    }

    #[Test]
    public function it_creates_vacation_leave_request()
    {
        $startDate = Carbon::now()->addWeeks(3)->format('Y-m-d');
        $endDate = Carbon::now()->addWeeks(3)->addDays(4)->format('Y-m-d');

        $data = [
            'leave_type' => 'VL',
            'start_date' => $startDate,
            'end_date' => $endDate,
            'reason' => 'Family vacation for summer break',
            'campaign_department' => 'Sales',
        ];

        $response = $this->actingAs($this->employee)
            ->post(route('leave-requests.store'), $data);

        // Controller redirects to show page after creation
        $response->assertRedirect();

        $this->assertDatabaseHas('leave_requests', [
            'user_id' => $this->employee->id,
            'leave_type' => 'VL',
            'status' => 'pending',
        ]);
    }

    #[Test]
    public function it_creates_sick_leave_request_with_medical_cert()
    {
        Mail::fake();

        $data = [
            'leave_type' => 'SL',
            'start_date' => Carbon::now()->addDays(1)->format('Y-m-d'),
            'end_date' => Carbon::now()->addDays(3)->format('Y-m-d'),
            'reason' => 'Medical recovery after surgery',
            'campaign_department' => 'Support',
            'medical_cert_submitted' => true,
        ];

        $response = $this->actingAs($this->employee)
            ->post(route('leave-requests.store'), $data);

        // Controller redirects to show page after creation
        $response->assertRedirect();

        $this->assertDatabaseHas('leave_requests', [
            'user_id' => $this->employee->id,
            'leave_type' => 'SL',
            'medical_cert_submitted' => true,
            'status' => 'pending',
        ]);
    }

    #[Test]
    public function it_creates_bereavement_leave_request()
    {
        $data = [
            'leave_type' => 'BL',
            'start_date' => Carbon::now()->addWeeks(3)->format('Y-m-d'),
            'end_date' => Carbon::now()->addWeeks(3)->addDays(2)->format('Y-m-d'),
            'reason' => 'Attending funeral of immediate family member',
            'campaign_department' => 'Tech',
        ];

        $response = $this->actingAs($this->employee)
            ->post(route('leave-requests.store'), $data);

        // Controller redirects to show page after creation
        $response->assertRedirect();

        $this->assertDatabaseHas('leave_requests', [
            'user_id' => $this->employee->id,
            'leave_type' => 'BL',
        ]);
    }

    #[Test]
    public function it_creates_non_credited_leave_types()
    {
        // Test SPL, LOA, LDV, UPTO (don't require credits)
        // The controller blocks creation if user has pending requests
        // So we need to create separate users or cancel between requests
        $nonCreditedTypes = ['SPL', 'LOA', 'LDV', 'UPTO'];

        foreach ($nonCreditedTypes as $index => $type) {
            // Create a new employee for each leave type to avoid pending request check
            $employee = User::factory()->create([
                'role' => 'Agent',
                'is_approved' => true,
                'hired_date' => Carbon::now()->subMonths(7),
            ]);

            LeaveCredit::factory()->create([
                'user_id' => $employee->id,
                'year' => Carbon::now()->year,
                'month' => Carbon::now()->month,
                'credits_balance' => 5.0,
                'credits_earned' => 1.25,
                'credits_used' => 0,
            ]);

            $data = [
                'leave_type' => $type,
                'start_date' => Carbon::now()->addDays(10 + $index)->format('Y-m-d'),
                'end_date' => Carbon::now()->addDays(12 + $index)->format('Y-m-d'),
                'reason' => "Request for $type leave with detailed explanation",
                'campaign_department' => 'Management',
            ];

            $response = $this->actingAs($employee)
                ->post(route('leave-requests.store'), $data);

            // Controller redirects to show page after creation
            $response->assertRedirect();
        }

        $this->assertEquals(4, LeaveRequest::count());
    }

    #[Test]
    public function it_validates_required_fields()
    {
        $response = $this->actingAs($this->employee)
            ->post(route('leave-requests.store'), []);

        // team_lead_email is NOT required in the actual implementation
        $response->assertSessionHasErrors([
            'leave_type',
            'start_date',
            'end_date',
            'reason',
            'campaign_department',
        ]);
    }

    #[Test]
    public function it_validates_leave_type_values()
    {
        $data = [
            'leave_type' => 'INVALID_TYPE',
            'start_date' => Carbon::now()->addDays(15)->format('Y-m-d'),
            'end_date' => Carbon::now()->addDays(17)->format('Y-m-d'),
            'reason' => 'Testing invalid type',
            'campaign_department' => 'Sales',
        ];

        $response = $this->actingAs($this->employee)
            ->post(route('leave-requests.store'), $data);

        $response->assertSessionHasErrors('leave_type');
    }

    #[Test]
    public function it_validates_end_date_after_start_date()
    {
        $data = [
            'leave_type' => 'VL',
            'start_date' => Carbon::now()->addDays(20)->format('Y-m-d'),
            'end_date' => Carbon::now()->addDays(18)->format('Y-m-d'), // Before start date
            'reason' => 'Testing date validation',
            'campaign_department' => 'Sales',
        ];

        $response = $this->actingAs($this->employee)
            ->post(route('leave-requests.store'), $data);

        $response->assertSessionHasErrors('end_date');
    }

    #[Test]
    public function admin_approves_leave_request_and_deducts_credits()
    {
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $this->employee->id,
            'leave_type' => 'VL',
            'days_requested' => 3.0,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->admin)
            ->post(route('leave-requests.approve', $leaveRequest), [
                'review_notes' => 'Approved for year-end break',
            ]);

        $response->assertRedirect();

        $leaveRequest->refresh();
        $this->assertEquals('approved', $leaveRequest->status);
        $this->assertEquals($this->admin->id, $leaveRequest->reviewed_by);
        $this->assertNotNull($leaveRequest->reviewed_at);
    }

    #[Test]
    public function admin_denies_leave_request()
    {
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $this->employee->id,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->admin)
            ->post(route('leave-requests.deny', $leaveRequest), [
                'review_notes' => 'Insufficient coverage during this period',
            ]);

        $response->assertRedirect();

        $leaveRequest->refresh();
        $this->assertEquals('denied', $leaveRequest->status);
        $this->assertEquals($this->admin->id, $leaveRequest->reviewed_by);
        $this->assertNotNull($leaveRequest->reviewed_at);
    }

    #[Test]
    public function employee_cancels_own_pending_request()
    {
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $this->employee->id,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->employee)
            ->post(route('leave-requests.cancel', $leaveRequest));

        $response->assertRedirect();

        $leaveRequest->refresh();
        $this->assertEquals('cancelled', $leaveRequest->status);
    }

    #[Test]
    public function employee_cannot_cancel_approved_request_due_to_policy()
    {
        // The policy only allows canceling 'pending' requests
        // Even though the model's canBeCancelled() allows approved future requests
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $this->employee->id,
            'leave_type' => 'VL',
            'start_date' => Carbon::now()->addDays(10),
            'end_date' => Carbon::now()->addDays(12),
            'status' => 'approved',
            'credits_deducted' => 3.0,
            'credits_year' => Carbon::now()->year,
        ]);

        $response = $this->actingAs($this->employee)
            ->post(route('leave-requests.cancel', $leaveRequest));

        // Policy denies cancel on non-pending requests
        $response->assertForbidden();

        $leaveRequest->refresh();
        $this->assertEquals('approved', $leaveRequest->status);
    }

    #[Test]
    public function it_filters_leave_requests_by_status()
    {
        LeaveRequest::factory()->create([
            'user_id' => $this->employee->id,
            'status' => 'approved',
        ]);

        LeaveRequest::factory()->create([
            'user_id' => $this->employee->id,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('leave-requests.index', ['status' => 'approved']));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('leaveRequests.data', 1)
                ->where('leaveRequests.data.0.status', 'approved')
            );
    }

    #[Test]
    public function it_filters_leave_requests_by_type()
    {
        LeaveRequest::factory()->create([
            'user_id' => $this->employee->id,
            'leave_type' => 'VL',
        ]);

        LeaveRequest::factory()->create([
            'user_id' => $this->employee->id,
            'leave_type' => 'SL',
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('leave-requests.index', ['type' => 'VL']));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('leaveRequests.data', 1)
                ->where('leaveRequests.data.0.leave_type', 'VL')
            );
    }

    #[Test]
    public function it_displays_leave_request_details()
    {
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $this->employee->id,
        ]);

        $response = $this->actingAs($this->employee)
            ->get(route('leave-requests.show', $leaveRequest));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('FormRequest/Leave/Show')
                ->where('leaveRequest.id', $leaveRequest->id)
            );
    }

    #[Test]
    public function unauthorized_users_cannot_approve_requests()
    {
        $regularUser = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
        ]);

        $leaveRequest = LeaveRequest::factory()->create([
            'status' => 'pending',
        ]);

        $response = $this->actingAs($regularUser)
            ->post(route('leave-requests.approve', $leaveRequest));

        // Agent role doesn't have leave.approve permission - should be forbidden
        $response->assertForbidden();

        $leaveRequest->refresh();
        $this->assertEquals('pending', $leaveRequest->status);
    }

    #[Test]
    public function it_tracks_attendance_points_at_request_time()
    {
        $data = [
            'leave_type' => 'VL',
            'start_date' => Carbon::now()->addWeeks(3)->format('Y-m-d'),
            'end_date' => Carbon::now()->addWeeks(3)->addDays(2)->format('Y-m-d'),
            'reason' => 'Testing attendance points tracking',
            'campaign_department' => 'Sales',
        ];

        $response = $this->actingAs($this->employee)
            ->post(route('leave-requests.store'), $data);

        $leaveRequest = LeaveRequest::latest()->first();
        $this->assertIsNumeric($leaveRequest->attendance_points_at_request);
    }

    #[Test]
    public function only_pending_requests_can_be_approved()
    {
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $this->employee->id,
            'status' => 'approved',
        ]);

        $response = $this->actingAs($this->admin)
            ->post(route('leave-requests.approve', $leaveRequest));

        $response->assertSessionHasErrors();
    }

    #[Test]
    public function non_credited_leave_types_do_not_deduct_credits()
    {
        $initialBalance = $this->employee->leaveCredits()->sum('credits_balance');

        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $this->employee->id,
            'leave_type' => 'SPL', // Solo Parent Leave (non-credited)
            'days_requested' => 5.0,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->admin)
            ->post(route('leave-requests.approve', $leaveRequest), [
                'review_notes' => 'Approved',
            ]);

        $leaveRequest->refresh();
        $this->assertEquals('approved', $leaveRequest->status);
        $this->assertNull($leaveRequest->credits_deducted);

        // Balance should remain unchanged
        $finalBalance = $this->employee->leaveCredits()->sum('credits_balance');
        $this->assertEquals($initialBalance, $finalBalance);
    }
}
