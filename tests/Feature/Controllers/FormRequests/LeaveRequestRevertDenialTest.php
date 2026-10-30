<?php

namespace Tests\Feature\Controllers\FormRequests;

use App\Http\Middleware\EnsureUserHasSchedule;
use App\Mail\LeaveRequestDenialReverted;
use App\Models\LeaveRequest;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LeaveRequestRevertDenialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->withoutMiddleware([
            ValidateCsrfToken::class,
            EnsureUserHasSchedule::class,
        ]);
    }

    protected function superAdmin(): User
    {
        return User::factory()->create(['role' => 'Super Admin', 'is_approved' => true]);
    }

    protected function employee(): User
    {
        return User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
            'email' => 'employee@example.com',
        ]);
    }

    /**
     * Future-dated leave denied by the given reviewer (HR/Admin path).
     */
    protected function deniedRequest(User $employee, User $deniedBy, array $overrides = []): LeaveRequest
    {
        return LeaveRequest::factory()->create(array_merge([
            'user_id' => $employee->id,
            'leave_type' => 'VL',
            'status' => 'denied',
            'start_date' => Carbon::today()->addDays(10),
            'end_date' => Carbon::today()->addDays(11),
            'days_requested' => 2,
            'reviewed_by' => $deniedBy->id,
            'reviewed_at' => now()->subDay(),
            'review_notes' => 'Denied due to staffing shortage',
        ], $overrides));
    }

    #[Test]
    public function super_admin_can_revert_hr_denial_and_keep_prior_admin_approval(): void
    {
        $superAdmin = $this->superAdmin();
        $hr = User::factory()->create(['role' => 'HR', 'is_approved' => true]);
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);
        $employee = $this->employee();

        $leaveRequest = $this->deniedRequest($employee, $hr, [
            'admin_approved_by' => $admin->id,
            'admin_approved_at' => now()->subDays(2),
            'admin_review_notes' => 'Approved by Admin',
        ]);

        $response = $this->actingAs($superAdmin)->post(route('leave-requests.revert-denial', $leaveRequest), [
            'revert_reason' => 'Staffing issue was resolved',
        ]);

        $response->assertRedirect(route('leave-requests.show', $leaveRequest));
        $response->assertSessionHas('success');

        $leaveRequest->refresh();

        $this->assertSame('pending', $leaveRequest->status);
        $this->assertNull($leaveRequest->reviewed_by);
        $this->assertNull($leaveRequest->reviewed_at);
        $this->assertNull($leaveRequest->review_notes);

        $this->assertSame($superAdmin->id, $leaveRequest->reverted_by);
        $this->assertNotNull($leaveRequest->reverted_at);
        $this->assertSame('Staffing issue was resolved', $leaveRequest->revert_reason);
        $this->assertSame($hr->id, $leaveRequest->reverted_denial_by);
        $this->assertSame('Denied due to staffing shortage', $leaveRequest->reverted_denial_notes);

        // Prior Admin approval is preserved (pre-denial state)
        $this->assertSame($admin->id, $leaveRequest->admin_approved_by);
        $this->assertNotNull($leaveRequest->admin_approved_at);
        $this->assertSame('Pending HR Approval', $leaveRequest->getApprovalStatusText());
    }

    #[Test]
    public function super_admin_can_revert_team_lead_rejection_back_to_tl_stage(): void
    {
        $superAdmin = $this->superAdmin();
        $tl = User::factory()->create(['role' => 'Team Lead', 'is_approved' => true]);
        $employee = $this->employee();

        $leaveRequest = $this->deniedRequest($employee, $tl, [
            'requires_tl_approval' => true,
            'tl_approved_by' => $tl->id,
            'tl_approved_at' => now()->subDay(),
            'tl_review_notes' => 'Team is short-staffed',
            'tl_rejected' => true,
            'review_notes' => 'Rejected by Team Lead: Team is short-staffed',
        ]);

        $this->actingAs($superAdmin)
            ->post(route('leave-requests.revert-denial', $leaveRequest))
            ->assertRedirect(route('leave-requests.show', $leaveRequest));

        $leaveRequest->refresh();

        $this->assertSame('pending', $leaveRequest->status);
        $this->assertFalse($leaveRequest->tl_rejected);
        $this->assertNull($leaveRequest->tl_approved_by);
        $this->assertNull($leaveRequest->tl_approved_at);
        $this->assertNull($leaveRequest->tl_review_notes);
        $this->assertTrue($leaveRequest->requires_tl_approval);
        $this->assertSame('Pending Team Lead Approval', $leaveRequest->getApprovalStatusText());

        $this->assertSame($tl->id, $leaveRequest->reverted_denial_by);
        $this->assertSame('Rejected by Team Lead: Team is short-staffed', $leaveRequest->reverted_denial_notes);
        $this->assertNull($leaveRequest->revert_reason);
    }

    #[Test]
    public function revert_reason_is_optional(): void
    {
        $superAdmin = $this->superAdmin();
        $hr = User::factory()->create(['role' => 'HR', 'is_approved' => true]);
        $leaveRequest = $this->deniedRequest($this->employee(), $hr);

        $this->actingAs($superAdmin)
            ->post(route('leave-requests.revert-denial', $leaveRequest))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('leave_requests', [
            'id' => $leaveRequest->id,
            'status' => 'pending',
            'revert_reason' => null,
        ]);
    }

    #[Test]
    public function revert_reason_cannot_exceed_max_length(): void
    {
        $superAdmin = $this->superAdmin();
        $hr = User::factory()->create(['role' => 'HR', 'is_approved' => true]);
        $leaveRequest = $this->deniedRequest($this->employee(), $hr);

        $this->actingAs($superAdmin)
            ->post(route('leave-requests.revert-denial', $leaveRequest), [
                'revert_reason' => str_repeat('a', 1001),
            ])
            ->assertSessionHasErrors('revert_reason');

        $this->assertDatabaseHas('leave_requests', [
            'id' => $leaveRequest->id,
            'status' => 'denied',
        ]);
    }

    #[Test]
    public function it_notifies_employee_and_original_denier(): void
    {
        $superAdmin = $this->superAdmin();
        $hr = User::factory()->create(['role' => 'HR', 'is_approved' => true]);
        $employee = $this->employee();
        $leaveRequest = $this->deniedRequest($employee, $hr);

        $this->actingAs($superAdmin)->post(route('leave-requests.revert-denial', $leaveRequest));

        $this->assertDatabaseHas('notifications', [
            'user_id' => $employee->id,
            'type' => 'leave_request',
            'title' => 'Leave Request Denial Reverted',
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $hr->id,
            'type' => 'leave_request',
            'title' => 'Leave Denial Reverted by Super Admin',
        ]);

        Mail::assertQueued(LeaveRequestDenialReverted::class, function ($mail) use ($employee, $leaveRequest) {
            return $mail->hasTo($employee->email) && $mail->leaveRequest->id === $leaveRequest->id;
        });
    }

    #[Test]
    public function it_does_not_notify_denier_when_super_admin_reverts_own_denial(): void
    {
        $superAdmin = $this->superAdmin();
        $employee = $this->employee();
        $leaveRequest = $this->deniedRequest($employee, $superAdmin);

        $this->actingAs($superAdmin)->post(route('leave-requests.revert-denial', $leaveRequest));

        $this->assertDatabaseHas('notifications', [
            'user_id' => $employee->id,
            'title' => 'Leave Request Denial Reverted',
        ]);

        $this->assertDatabaseMissing('notifications', [
            'user_id' => $superAdmin->id,
            'title' => 'Leave Denial Reverted by Super Admin',
        ]);
    }

    #[Test]
    public function non_super_admin_roles_cannot_revert_denial(): void
    {
        $hr = User::factory()->create(['role' => 'HR', 'is_approved' => true]);
        $employee = $this->employee();
        $leaveRequest = $this->deniedRequest($employee, $hr);

        foreach (['Admin', 'HR', 'Team Lead'] as $role) {
            $user = User::factory()->create(['role' => $role, 'is_approved' => true]);

            $this->actingAs($user)
                ->post(route('leave-requests.revert-denial', $leaveRequest))
                ->assertForbidden();
        }

        $this->actingAs($employee)
            ->post(route('leave-requests.revert-denial', $leaveRequest))
            ->assertForbidden();

        $this->assertDatabaseHas('leave_requests', [
            'id' => $leaveRequest->id,
            'status' => 'denied',
        ]);
    }

    #[Test]
    public function it_cannot_revert_non_denied_requests(): void
    {
        $superAdmin = $this->superAdmin();
        $hr = User::factory()->create(['role' => 'HR', 'is_approved' => true]);
        $employee = $this->employee();

        foreach (['pending', 'approved', 'cancelled'] as $status) {
            $leaveRequest = $this->deniedRequest($employee, $hr, ['status' => $status]);

            $this->actingAs($superAdmin)
                ->post(route('leave-requests.revert-denial', $leaveRequest))
                ->assertForbidden();

            $this->assertDatabaseHas('leave_requests', [
                'id' => $leaveRequest->id,
                'status' => $status,
            ]);
        }
    }

    #[Test]
    public function it_cannot_revert_denial_when_leave_end_date_has_passed(): void
    {
        $superAdmin = $this->superAdmin();
        $hr = User::factory()->create(['role' => 'HR', 'is_approved' => true]);
        $leaveRequest = $this->deniedRequest($this->employee(), $hr, [
            'start_date' => Carbon::today()->subDays(5),
            'end_date' => Carbon::today()->subDays(4),
        ]);

        $this->actingAs($superAdmin)
            ->post(route('leave-requests.revert-denial', $leaveRequest))
            ->assertForbidden();

        $this->assertDatabaseHas('leave_requests', [
            'id' => $leaveRequest->id,
            'status' => 'denied',
        ]);
    }

    #[Test]
    public function show_page_exposes_can_revert_denial_only_to_super_admin(): void
    {
        $superAdmin = $this->superAdmin();
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);
        $hr = User::factory()->create(['role' => 'HR', 'is_approved' => true]);
        $leaveRequest = $this->deniedRequest($this->employee(), $hr);

        $this->actingAs($superAdmin)
            ->get(route('leave-requests.show', $leaveRequest))
            ->assertInertia(fn ($page) => $page
                ->component('FormRequest/Leave/Show')
                ->where('canRevertDenial', true)
            );

        $this->actingAs($admin)
            ->get(route('leave-requests.show', $leaveRequest))
            ->assertInertia(fn ($page) => $page
                ->component('FormRequest/Leave/Show')
                ->where('canRevertDenial', false)
            );
    }

    #[Test]
    public function show_page_includes_reverter_and_previous_denier_after_revert(): void
    {
        $superAdmin = $this->superAdmin();
        $hr = User::factory()->create(['role' => 'HR', 'is_approved' => true]);
        $leaveRequest = $this->deniedRequest($this->employee(), $hr);

        $this->actingAs($superAdmin)->post(route('leave-requests.revert-denial', $leaveRequest), [
            'revert_reason' => 'Second look requested',
        ]);

        $this->actingAs($superAdmin)
            ->get(route('leave-requests.show', $leaveRequest))
            ->assertInertia(fn ($page) => $page
                ->component('FormRequest/Leave/Show')
                ->where('leaveRequest.status', 'pending')
                ->where('leaveRequest.revert_reason', 'Second look requested')
                ->where('leaveRequest.reverter.id', $superAdmin->id)
                ->where('leaveRequest.previous_denier.id', $hr->id)
                ->where('leaveRequest.reverted_denial_by', $hr->id)
                ->where('canRevertDenial', false)
            );
    }

    #[Test]
    public function reverted_request_can_be_denied_again(): void
    {
        $superAdmin = $this->superAdmin();
        $hr = User::factory()->create(['role' => 'HR', 'is_approved' => true]);
        $leaveRequest = $this->deniedRequest($this->employee(), $hr);

        $this->actingAs($superAdmin)->post(route('leave-requests.revert-denial', $leaveRequest));

        $this->actingAs($hr)
            ->post(route('leave-requests.deny', $leaveRequest), [
                'review_notes' => 'Still not possible this period',
            ])
            ->assertRedirect();

        $leaveRequest->refresh();

        $this->assertSame('denied', $leaveRequest->status);
        $this->assertSame($hr->id, $leaveRequest->reviewed_by);
        $this->assertSame('Still not possible this period', $leaveRequest->review_notes);
    }
}
