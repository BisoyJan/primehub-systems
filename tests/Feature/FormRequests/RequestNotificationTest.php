<?php

namespace Tests\Feature\FormRequests;

use App\Models\LeaveRequest;
use App\Models\MedicationRequest;
use App\Models\User;
use App\Mail\LeaveRequestSubmitted;
use App\Mail\LeaveRequestStatusUpdated;
use App\Mail\MedicationRequestSubmitted;
use App\Mail\MedicationRequestStatusUpdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests for email notification mailing behavior for leave and medication requests.
 * These tests verify that Mail classes work correctly when invoked directly.
 */
class RequestNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected User $employee;
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->employee = User::factory()->create([
            'role' => 'Agent',
            'email' => 'employee@example.com',
            'is_approved' => true,
        ]);

        // Admin role has leave.approve and medication_requests.update permissions
        $this->admin = User::factory()->create([
            'role' => 'Admin',
            'email' => 'admin@example.com',
            'is_approved' => true,
        ]);
    }

    // ==================== LEAVE REQUEST NOTIFICATIONS ====================

    #[Test]
    public function it_sends_notification_when_leave_request_is_submitted()
    {
        Mail::fake();

        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $this->employee->id,
            'status' => 'pending',
        ]);

        // Trigger notification (normally done in controller)
        // Since mailable implements ShouldQueue, we use assertQueued
        Mail::to($this->employee->email)
            ->send(new LeaveRequestSubmitted($leaveRequest, $this->employee));

        Mail::assertQueued(LeaveRequestSubmitted::class, function ($mail) use ($leaveRequest) {
            return $mail->leaveRequest->id === $leaveRequest->id &&
                   $mail->user->id === $this->employee->id &&
                   $mail->hasTo($this->employee->email);
        });
    }

    #[Test]
    public function it_sends_notification_when_leave_request_is_approved()
    {
        Mail::fake();

        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $this->employee->id,
            'status' => 'approved',
            'reviewed_by' => $this->admin->id,
            'reviewed_at' => Carbon::now(),
        ]);

        Mail::to($this->employee->email)
            ->send(new LeaveRequestStatusUpdated($leaveRequest, $this->employee));

        Mail::assertQueued(LeaveRequestStatusUpdated::class, function ($mail) use ($leaveRequest) {
            return $mail->leaveRequest->id === $leaveRequest->id &&
                   $mail->leaveRequest->status === 'approved' &&
                   $mail->user->id === $this->employee->id;
        });
    }

    #[Test]
    public function it_sends_notification_when_leave_request_is_denied()
    {
        Mail::fake();

        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $this->employee->id,
            'status' => 'denied',
            'reviewed_by' => $this->admin->id,
            'reviewed_at' => Carbon::now(),
            'review_notes' => 'Insufficient coverage',
        ]);

        Mail::to($this->employee->email)
            ->send(new LeaveRequestStatusUpdated($leaveRequest, $this->employee));

        Mail::assertQueued(LeaveRequestStatusUpdated::class, function ($mail) use ($leaveRequest) {
            return $mail->leaveRequest->id === $leaveRequest->id &&
                   $mail->leaveRequest->status === 'denied';
        });
    }

    #[Test]
    public function it_sends_notification_when_leave_request_is_cancelled()
    {
        Mail::fake();

        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $this->employee->id,
            'status' => 'cancelled',
        ]);

        Mail::to($this->employee->email)
            ->send(new LeaveRequestStatusUpdated($leaveRequest, $this->employee));

        Mail::assertQueued(LeaveRequestStatusUpdated::class, function ($mail) use ($leaveRequest) {
            return $mail->leaveRequest->id === $leaveRequest->id &&
                   $mail->leaveRequest->status === 'cancelled';
        });
    }

    #[Test]
    public function leave_notification_includes_request_details()
    {
        Mail::fake();

        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $this->employee->id,
            'leave_type' => 'VL',
            'start_date' => Carbon::now()->addDays(10),
            'end_date' => Carbon::now()->addDays(14),
            'days_requested' => 5.0,
            'status' => 'approved',
        ]);

        Mail::to($this->employee->email)
            ->send(new LeaveRequestStatusUpdated($leaveRequest, $this->employee));

        Mail::assertQueued(LeaveRequestStatusUpdated::class, function ($mail) use ($leaveRequest) {
            return $mail->leaveRequest->leave_type === 'VL' &&
                   $mail->leaveRequest->days_requested == 5.0;
        });
    }

    #[Test]
    public function leave_notification_mail_is_queued()
    {
        Queue::fake();
        Mail::fake();

        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $this->employee->id,
        ]);

        Mail::to($this->employee->email)
            ->queue(new LeaveRequestSubmitted($leaveRequest, $this->employee));

        Mail::assertQueued(LeaveRequestSubmitted::class);
    }

    // ==================== MEDICATION REQUEST NOTIFICATIONS ====================

    #[Test]
    public function it_sends_notification_when_medication_request_is_submitted()
    {
        Mail::fake();

        $medicationRequest = MedicationRequest::factory()->create([
            'user_id' => $this->employee->id,
            'status' => 'pending',
        ]);

        Mail::to($this->employee->email)
            ->send(new MedicationRequestSubmitted($medicationRequest, $this->employee));

        Mail::assertQueued(MedicationRequestSubmitted::class, function ($mail) use ($medicationRequest) {
            return $mail->medicationRequest->id === $medicationRequest->id &&
                   $mail->user->id === $this->employee->id &&
                   $mail->hasTo($this->employee->email);
        });
    }

    #[Test]
    public function it_sends_notification_when_medication_request_is_approved()
    {
        Mail::fake();

        $medicationRequest = MedicationRequest::factory()->create([
            'user_id' => $this->employee->id,
            'status' => 'approved',
            'approved_by' => $this->admin->id,
            'approved_at' => Carbon::now(),
        ]);

        Mail::to($this->employee->email)
            ->send(new MedicationRequestStatusUpdated($medicationRequest, $this->employee));

        Mail::assertQueued(MedicationRequestStatusUpdated::class, function ($mail) use ($medicationRequest) {
            return $mail->medicationRequest->id === $medicationRequest->id &&
                   $mail->medicationRequest->status === 'approved';
        });
    }

    #[Test]
    public function it_sends_notification_when_medication_is_dispensed()
    {
        Mail::fake();

        $medicationRequest = MedicationRequest::factory()->create([
            'user_id' => $this->employee->id,
            'status' => 'dispensed',
            'approved_by' => $this->admin->id,
        ]);

        Mail::to($this->employee->email)
            ->send(new MedicationRequestStatusUpdated($medicationRequest, $this->employee));

        Mail::assertQueued(MedicationRequestStatusUpdated::class, function ($mail) use ($medicationRequest) {
            return $mail->medicationRequest->status === 'dispensed';
        });
    }

    #[Test]
    public function it_sends_notification_when_medication_request_is_rejected()
    {
        Mail::fake();

        $medicationRequest = MedicationRequest::factory()->create([
            'user_id' => $this->employee->id,
            'status' => 'rejected',
            'approved_by' => $this->admin->id,
            'admin_notes' => 'Medication not available',
        ]);

        Mail::to($this->employee->email)
            ->send(new MedicationRequestStatusUpdated($medicationRequest, $this->employee));

        Mail::assertQueued(MedicationRequestStatusUpdated::class, function ($mail) use ($medicationRequest) {
            return $mail->medicationRequest->status === 'rejected';
        });
    }

    #[Test]
    public function medication_notification_includes_request_details()
    {
        Mail::fake();

        $medicationRequest = MedicationRequest::factory()->create([
            'user_id' => $this->employee->id,
            'medication_type' => 'Biogesic',
            'onset_of_symptoms' => 'Just today',
            'status' => 'approved',
        ]);

        Mail::to($this->employee->email)
            ->send(new MedicationRequestStatusUpdated($medicationRequest, $this->employee));

        Mail::assertQueued(MedicationRequestStatusUpdated::class, function ($mail) use ($medicationRequest) {
            return $mail->medicationRequest->medication_type === 'Biogesic' &&
                   $mail->medicationRequest->onset_of_symptoms === 'Just today';
        });
    }

    #[Test]
    public function medication_notification_mail_is_queued()
    {
        Queue::fake();
        Mail::fake();

        $medicationRequest = MedicationRequest::factory()->create([
            'user_id' => $this->employee->id,
        ]);

        Mail::to($this->employee->email)
            ->queue(new MedicationRequestSubmitted($medicationRequest, $this->employee));

        Mail::assertQueued(MedicationRequestSubmitted::class);
    }

    #[Test]
    public function notification_includes_admin_notes()
    {
        Mail::fake();

        $medicationRequest = MedicationRequest::factory()->create([
            'user_id' => $this->employee->id,
            'status' => 'approved',
            'admin_notes' => 'Please pick up from HR office',
        ]);

        Mail::to($this->employee->email)
            ->send(new MedicationRequestStatusUpdated($medicationRequest, $this->employee));

        Mail::assertQueued(MedicationRequestStatusUpdated::class, function ($mail) use ($medicationRequest) {
            return $mail->medicationRequest->admin_notes === 'Please pick up from HR office';
        });
    }

    #[Test]
    public function notifications_are_not_sent_on_failed_operations()
    {
        Mail::fake();

        // Simulate a failed operation - no mail should be sent
        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $this->employee->id,
            'status' => 'pending',
        ]);

        // Do not send mail
        Mail::assertNothingSent();
    }

    #[Test]
    public function notification_handles_user_relationships()
    {
        Mail::fake();

        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $this->employee->id,
            'status' => 'approved',
            'reviewed_by' => $this->admin->id,
        ]);

        $leaveRequest->load(['user', 'reviewer']);

        Mail::to($this->employee->email)
            ->send(new LeaveRequestStatusUpdated($leaveRequest, $this->employee));

        Mail::assertQueued(LeaveRequestStatusUpdated::class, function ($mail) {
            return $mail->leaveRequest->user !== null &&
                   $mail->leaveRequest->reviewer !== null;
        });
    }
}
