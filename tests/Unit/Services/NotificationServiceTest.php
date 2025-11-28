<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Notification;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    private NotificationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new NotificationService();
    }

    #[Test]
    public function it_creates_notification_for_user(): void
    {
        $user = User::factory()->create();

        $notification = $this->service->create(
            $user,
            'test_type',
            'Test Title',
            'Test message',
            ['key' => 'value']
        );

        $this->assertInstanceOf(Notification::class, $notification);
        $this->assertEquals($user->id, $notification->user_id);
        $this->assertEquals('test_type', $notification->type);
        $this->assertEquals('Test Title', $notification->title);
        $this->assertEquals('Test message', $notification->message);
        $this->assertEquals(['key' => 'value'], $notification->data);
    }

    #[Test]
    public function it_creates_notification_with_user_id(): void
    {
        $user = User::factory()->create();

        $notification = $this->service->create(
            $user->id,
            'test_type',
            'Test Title',
            'Test message'
        );

        $this->assertEquals($user->id, $notification->user_id);
    }

    #[Test]
    public function it_creates_notification_without_data(): void
    {
        $user = User::factory()->create();

        $notification = $this->service->create(
            $user,
            'test_type',
            'Test Title',
            'Test message'
        );

        $this->assertNull($notification->data);
    }

    #[Test]
    public function it_creates_notifications_for_multiple_users(): void
    {
        $users = User::factory()->count(3)->create();
        $userIds = $users->pluck('id')->toArray();

        $this->service->createForMultipleUsers(
            $userIds,
            'bulk_type',
            'Bulk Title',
            'Bulk message',
            ['bulk' => true]
        );

        $this->assertDatabaseCount('notifications', 3);
        foreach ($userIds as $userId) {
            $this->assertDatabaseHas('notifications', [
                'user_id' => $userId,
                'type' => 'bulk_type',
                'title' => 'Bulk Title',
            ]);
        }
    }

    #[Test]
    public function it_notifies_maintenance_due(): void
    {
        $user = User::factory()->create();

        $notification = $this->service->notifyMaintenanceDue($user, 'Station 001', '2025-12-01');

        $this->assertEquals($user->id, $notification->user_id);
        $this->assertEquals('maintenance_due', $notification->type);
        $this->assertEquals('Maintenance Due', $notification->title);
        $this->assertStringContainsString('Station 001', $notification->message);
        $this->assertStringContainsString('2025-12-01', $notification->message);
    }

    #[Test]
    public function it_notifies_leave_request(): void
    {
        $user = User::factory()->create();

        $notification = $this->service->notifyLeaveRequest($user, 'John Doe', 'VL', 123);

        $this->assertEquals('leave_request', $notification->type);
        $this->assertEquals('New Leave Request', $notification->title);
        $this->assertStringContainsString('John Doe', $notification->message);
        $this->assertStringContainsString('VL', $notification->message);
        $this->assertEquals(123, $notification->data['request_id']);
    }

    #[Test]
    public function it_notifies_leave_request_status_change(): void
    {
        $user = User::factory()->create();

        $notification = $this->service->notifyLeaveRequestStatusChange($user, 'approved', 'VL', 123);

        $this->assertEquals('leave_request', $notification->type);
        $this->assertEquals('Leave Request Approved', $notification->title);
        $this->assertStringContainsString('approved', $notification->message);
        $this->assertStringContainsString('VL', $notification->message);
        $this->assertEquals('approved', $notification->data['status']);
        $this->assertArrayHasKey('link', $notification->data);
    }

    #[Test]
    public function it_notifies_it_concern(): void
    {
        $user = User::factory()->create();

        $notification = $this->service->notifyItConcern($user, 'Station 001', 'hardware', 456);

        $this->assertEquals('it_concern', $notification->type);
        $this->assertEquals('New IT Concern', $notification->title);
        $this->assertStringContainsString('hardware', $notification->message);
        $this->assertStringContainsString('Station 001', $notification->message);
        $this->assertEquals(456, $notification->data['concern_id']);
    }

    #[Test]
    public function it_notifies_it_concern_status_change(): void
    {
        $user = User::factory()->create();

        $notification = $this->service->notifyItConcernStatusChange($user, 'resolved', 'Station 001', 456);

        $this->assertEquals('it_concern', $notification->type);
        $this->assertEquals('IT Concern Resolved', $notification->title);
        $this->assertStringContainsString('Resolved', $notification->message);
    }

    #[Test]
    public function it_notifies_medication_request(): void
    {
        $user = User::factory()->create();

        $notification = $this->service->notifyMedicationRequest($user, 'Jane Smith', 789);

        $this->assertEquals('medication_request', $notification->type);
        $this->assertEquals('New Medication Request', $notification->title);
        $this->assertStringContainsString('Jane Smith', $notification->message);
    }

    #[Test]
    public function it_notifies_pc_assignment(): void
    {
        $user = User::factory()->create();

        $notification = $this->service->notifyPcAssignment($user, 'PC-001', 'Station 005');

        $this->assertEquals('pc_assignment', $notification->type);
        $this->assertEquals('PC Assignment', $notification->title);
        $this->assertStringContainsString('PC-001', $notification->message);
        $this->assertStringContainsString('Station 005', $notification->message);
    }

    #[Test]
    public function it_notifies_system_message(): void
    {
        $user = User::factory()->create();

        $notification = $this->service->notifySystemMessage($user, 'System Alert', 'Maintenance scheduled', ['urgent' => true]);

        $this->assertEquals('system', $notification->type);
        $this->assertEquals('System Alert', $notification->title);
        $this->assertEquals('Maintenance scheduled', $notification->message);
        $this->assertTrue($notification->data['urgent']);
    }

    #[Test]
    public function it_notifies_all_approved_users(): void
    {
        User::factory()->count(3)->create(['is_approved' => true]);
        User::factory()->count(2)->create(['is_approved' => false]);

        $this->service->notifyAllUsers('Announcement', 'System update tonight', ['priority' => 'high']);

        // Only approved users should receive notification
        $this->assertDatabaseCount('notifications', 3);
    }

    #[Test]
    public function it_notifies_users_by_role(): void
    {
        User::factory()->count(2)->create(['role' => 'Admin', 'is_approved' => true]);
        User::factory()->count(3)->create(['role' => 'Agent', 'is_approved' => true]);

        $this->service->notifyUsersByRole('Admin', 'system', 'Admin Alert', 'Admin only message');

        // Only admins should receive notification
        $notifications = Notification::all();
        $this->assertCount(2, $notifications);
        foreach ($notifications as $notification) {
            $this->assertEquals('Admin', $notification->user->role);
        }
    }

    #[Test]
    public function it_notifies_attendance_status(): void
    {
        $user = User::factory()->create();

        $notification = $this->service->notifyAttendanceStatus($user, 'on_time', '2025-11-27', null);

        $this->assertEquals('attendance_status', $notification->type);
        $this->assertEquals('Attendance Verified', $notification->title);
        $this->assertStringContainsString('On time', $notification->message);
        $this->assertStringContainsString('2025-11-27', $notification->message);
    }

    #[Test]
    public function it_notifies_attendance_status_with_points(): void
    {
        $user = User::factory()->create();

        $notification = $this->service->notifyAttendanceStatus($user, 'tardy', '2025-11-27', 0.5);

        $this->assertStringContainsString('0.5', $notification->message);
        $this->assertStringContainsString('attendance point', $notification->message);
        $this->assertEquals(0.5, $notification->data['points']);
    }

    #[Test]
    public function it_notifies_it_roles_about_new_concern(): void
    {
        User::factory()->count(2)->create(['role' => 'IT', 'is_approved' => true]);
        User::factory()->create(['role' => 'Super Admin', 'is_approved' => true]);
        User::factory()->create(['role' => 'Agent', 'is_approved' => true]); // Should not receive

        $this->service->notifyItRolesAboutNewConcern(
            'Station 001',
            'Main Office',
            'John Doe',
            'hardware',
            'high',
            'Monitor not working',
            123
        );

        // IT and Super Admin should receive (3 total)
        $this->assertDatabaseCount('notifications', 3);
    }

    #[Test]
    public function it_notifies_it_roles_about_concern_update(): void
    {
        User::factory()->create(['role' => 'IT', 'is_approved' => true]);
        User::factory()->create(['role' => 'Super Admin', 'is_approved' => true]);

        $this->service->notifyItRolesAboutConcernUpdate('Station 001', 'Main Office', 'John Doe', 123);

        $this->assertDatabaseCount('notifications', 2);
        $this->assertDatabaseHas('notifications', [
            'type' => 'it_concern',
            'title' => 'IT Concern Updated',
        ]);
    }

    #[Test]
    public function it_notifies_hr_roles_about_new_leave_request(): void
    {
        User::factory()->create(['role' => 'HR', 'is_approved' => true]);
        User::factory()->create(['role' => 'Admin', 'is_approved' => true]);
        User::factory()->create(['role' => 'Super Admin', 'is_approved' => true]);

        $this->service->notifyHrRolesAboutNewLeaveRequest(
            'John Doe',
            'VL',
            '2025-12-01',
            '2025-12-05',
            123
        );

        // HR, Admin, Super Admin should receive (3 total)
        $this->assertDatabaseCount('notifications', 3);
    }

    #[Test]
    public function it_notifies_hr_roles_about_leave_cancellation(): void
    {
        User::factory()->create(['role' => 'HR', 'is_approved' => true]);
        User::factory()->create(['role' => 'Admin', 'is_approved' => true]);
        User::factory()->create(['role' => 'Super Admin', 'is_approved' => true]);

        $this->service->notifyHrRolesAboutLeaveCancellation(
            'John Doe',
            'VL',
            '2025-12-01',
            '2025-12-05'
        );

        $this->assertDatabaseCount('notifications', 3);
        $this->assertDatabaseHas('notifications', [
            'title' => 'Leave Request Cancelled',
        ]);
    }

    #[Test]
    public function it_notifies_hr_roles_about_new_medication_request(): void
    {
        User::factory()->create(['role' => 'HR', 'is_approved' => true]);
        User::factory()->create(['role' => 'Admin', 'is_approved' => true]);

        $this->service->notifyHrRolesAboutNewMedicationRequest('John Doe', 'Paracetamol', 456);

        $this->assertDatabaseCount('notifications', 2);
    }

    #[Test]
    public function it_notifies_medication_request_status_change(): void
    {
        $user = User::factory()->create();

        $notification = $this->service->notifyMedicationRequestStatusChange($user, 'approved', 'Paracetamol', 456);

        $this->assertEquals('medication_request', $notification->type);
        $this->assertEquals('Medication Request Approved', $notification->title);
        $this->assertStringContainsString('Paracetamol', $notification->message);
    }

    #[Test]
    public function it_notifies_hr_roles_about_medication_request_cancellation(): void
    {
        User::factory()->create(['role' => 'HR', 'is_approved' => true]);

        $this->service->notifyHrRolesAboutMedicationRequestCancellation('John Doe', 'Paracetamol');

        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseHas('notifications', [
            'title' => 'Medication Request Cancelled',
        ]);
    }
}
