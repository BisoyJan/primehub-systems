<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendancePoint;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestDay;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Covers uploading multiple supporting documents (up to 10 images/PDFs) on
 * leave requests for the leave types that allow optional document upload.
 */
class LeaveRequestDocumentUploadTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'leave_type' => 'BL',
            'start_date' => now()->addDays(3)->toDateString(),
            'end_date' => now()->addDays(4)->toDateString(),
            'reason' => 'Bereavement leave for a close family member.',
            'campaign_department' => 'Sales',
        ], $overrides);
    }

    #[Test]
    public function it_stores_multiple_uploaded_documents(): void
    {
        Mail::fake();
        Storage::fake('local');

        $user = User::factory()->create(['role' => 'HR', 'is_approved' => true, 'hired_date' => now()->subYears(1)]);

        $files = [
            UploadedFile::fake()->image('doc1.jpg'),
            UploadedFile::fake()->image('doc2.png'),
            UploadedFile::fake()->create('doc3.pdf', 100, 'application/pdf'),
        ];

        $response = $this->actingAs($user)
            ->post(route('leave-requests.store'), $this->payload([
                'medical_cert_files' => $files,
            ]));

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $leaveRequest = LeaveRequest::firstOrFail();

        $this->assertSame(3, $leaveRequest->documents()->count());
        $this->assertTrue((bool) $leaveRequest->medical_cert_submitted);

        foreach ($leaveRequest->documents as $document) {
            Storage::disk('local')->assertExists($document->file_path);
        }
    }

    #[Test]
    public function it_rejects_more_than_ten_documents(): void
    {
        Mail::fake();
        Storage::fake('local');

        $user = User::factory()->create(['role' => 'HR', 'is_approved' => true, 'hired_date' => now()->subYears(1)]);

        $files = [];
        for ($i = 0; $i < 11; $i++) {
            $files[] = UploadedFile::fake()->image("doc{$i}.jpg");
        }

        $response = $this->actingAs($user)
            ->post(route('leave-requests.store'), $this->payload([
                'medical_cert_files' => $files,
            ]));

        $response->assertSessionHasErrors('medical_cert_files');
        $this->assertSame(0, LeaveRequest::count());
    }

    #[Test]
    public function it_removes_selected_documents_and_adds_new_ones_on_update(): void
    {
        Mail::fake();
        Storage::fake('local');

        $user = User::factory()->create(['role' => 'HR', 'is_approved' => true, 'hired_date' => now()->subYears(1)]);

        $this->actingAs($user)
            ->post(route('leave-requests.store'), $this->payload([
                'medical_cert_files' => [
                    UploadedFile::fake()->image('first.jpg'),
                    UploadedFile::fake()->image('second.jpg'),
                ],
            ]))
            ->assertSessionHasNoErrors();

        $leaveRequest = LeaveRequest::firstOrFail();
        $this->assertSame(2, $leaveRequest->documents()->count());

        $documentToRemove = $leaveRequest->documents()->first();
        $removedPath = $documentToRemove->file_path;

        $this->actingAs($user)
            ->put(route('leave-requests.update', $leaveRequest), $this->payload([
                'removed_documents' => [$documentToRemove->id],
                'medical_cert_files' => [
                    UploadedFile::fake()->image('third.jpg'),
                ],
            ]))
            ->assertSessionHasNoErrors();

        $leaveRequest->refresh();

        $this->assertSame(2, $leaveRequest->documents()->count());
        $this->assertDatabaseMissing('leave_request_documents', ['id' => $documentToRemove->id]);
        Storage::disk('local')->assertMissing($removedPath);
    }

    #[Test]
    public function it_attaches_document_to_approved_sl_and_excuses_upto_day_points(): void
    {
        Mail::fake();
        Storage::fake('local');

        $superAdmin = User::factory()->create(['role' => 'Super Admin', 'is_approved' => true]);
        $agent = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);
        $date = now()->subDays(5)->toDateString();

        $leaveRequest = LeaveRequest::factory()->create([
            'user_id' => $agent->id,
            'leave_type' => 'SL',
            'status' => 'approved',
            'start_date' => $date,
            'end_date' => $date,
            'days_requested' => 1,
            'credits_deducted' => 0,
            'medical_cert_submitted' => false,
            'campaign_department' => 'Sales',
        ]);
        LeaveRequestDay::create([
            'leave_request_id' => $leaveRequest->id,
            'date' => $date,
            'day_status' => LeaveRequestDay::STATUS_ADVISED_ABSENCE,
        ]);
        $attendance = Attendance::factory()->create([
            'user_id' => $agent->id,
            'shift_date' => $date,
            'status' => 'advised_absence',
            'leave_request_id' => $leaveRequest->id,
        ]);
        $point = AttendancePoint::factory()->create([
            'user_id' => $agent->id,
            'attendance_id' => $attendance->id,
            'shift_date' => $date,
            'point_type' => 'whole_day_absence',
            'points' => 1.00,
        ]);

        $this->actingAs($superAdmin)
            ->put(route('leave-requests.update', $leaveRequest), [
                'leave_type' => 'SL',
                'start_date' => $date,
                'end_date' => $date,
                'reason' => $leaveRequest->reason,
                'campaign_department' => 'Sales',
                'medical_cert_files' => [UploadedFile::fake()->image('medcert.jpg')],
            ])
            ->assertSessionHasNoErrors();

        $leaveRequest->refresh();

        $this->assertSame(1, $leaveRequest->documents()->count());
        $this->assertTrue((bool) $leaveRequest->medical_cert_submitted);
        $this->assertTrue((bool) $point->fresh()->is_excused);
        $this->assertSame('advised_absence', $attendance->fresh()->status);
    }

    #[Test]
    public function it_stores_supporting_documents_for_leave_of_absence(): void
    {
        Mail::fake();
        Storage::fake('local');

        $user = User::factory()->create(['role' => 'HR', 'is_approved' => true, 'hired_date' => now()->subYears(1)]);

        $this->actingAs($user)
            ->post(route('leave-requests.store'), $this->payload([
                'leave_type' => 'LOA',
                'reason' => 'Leave of absence for an extended personal matter.',
                'medical_cert_files' => [
                    UploadedFile::fake()->image('loa1.jpg'),
                    UploadedFile::fake()->create('loa2.pdf', 100, 'application/pdf'),
                ],
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $leaveRequest = LeaveRequest::firstOrFail();

        $this->assertSame('LOA', $leaveRequest->leave_type);
        $this->assertSame(2, $leaveRequest->documents()->count());
        $this->assertTrue((bool) $leaveRequest->medical_cert_submitted);

        foreach ($leaveRequest->documents as $document) {
            Storage::disk('local')->assertExists($document->file_path);
        }
    }

    #[Test]
    public function it_adds_supporting_documents_to_leave_of_absence_on_update(): void
    {
        Mail::fake();
        Storage::fake('local');

        $user = User::factory()->create(['role' => 'HR', 'is_approved' => true, 'hired_date' => now()->subYears(1)]);

        $loaPayload = [
            'leave_type' => 'LOA',
            'reason' => 'Leave of absence for an extended personal matter.',
        ];

        $this->actingAs($user)
            ->post(route('leave-requests.store'), $this->payload($loaPayload))
            ->assertSessionHasNoErrors();

        $leaveRequest = LeaveRequest::firstOrFail();
        $this->assertSame(0, $leaveRequest->documents()->count());

        $this->actingAs($user)
            ->put(route('leave-requests.update', $leaveRequest), $this->payload(array_merge($loaPayload, [
                'medical_cert_files' => [UploadedFile::fake()->image('loa.jpg')],
            ])))
            ->assertSessionHasNoErrors();

        $leaveRequest->refresh();

        $this->assertSame(1, $leaveRequest->documents()->count());
        $this->assertTrue((bool) $leaveRequest->medical_cert_submitted);
    }

    #[Test]
    public function it_allows_viewing_an_uploaded_document(): void
    {
        Mail::fake();
        Storage::fake('local');

        $user = User::factory()->create(['role' => 'HR', 'is_approved' => true, 'hired_date' => now()->subYears(1)]);

        $this->actingAs($user)
            ->post(route('leave-requests.store'), $this->payload([
                'medical_cert_files' => [UploadedFile::fake()->image('cert.jpg')],
            ]))
            ->assertSessionHasNoErrors();

        $leaveRequest = LeaveRequest::firstOrFail();
        $document = $leaveRequest->documents()->firstOrFail();

        $this->actingAs($user)
            ->get(route('leave-requests.documents', [
                'leaveRequest' => $leaveRequest->id,
                'document' => $document->id,
            ]))
            ->assertOk();
    }

    #[Test]
    public function it_stores_supporting_documents_for_maternity_leave(): void
    {
        Mail::fake();
        Storage::fake('local');

        $user = User::factory()->create(['role' => 'HR', 'is_approved' => true, 'hired_date' => now()->subYears(1)]);

        $this->actingAs($user)
            ->post(route('leave-requests.store'), $this->payload([
                'leave_type' => 'ML',
                'reason' => 'Maternity leave supporting medical records attached.',
                'medical_cert_files' => [
                    UploadedFile::fake()->image('ml1.jpg'),
                    UploadedFile::fake()->create('ml2.pdf', 100, 'application/pdf'),
                ],
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $leaveRequest = LeaveRequest::firstOrFail();

        $this->assertSame('ML', $leaveRequest->leave_type);
        $this->assertSame(2, $leaveRequest->documents()->count());
        $this->assertTrue((bool) $leaveRequest->medical_cert_submitted);

        foreach ($leaveRequest->documents as $document) {
            Storage::disk('local')->assertExists($document->file_path);
        }
    }

    #[Test]
    public function it_stores_supporting_documents_for_vacation_leave(): void
    {
        Mail::fake();
        Storage::fake('local');

        $user = User::factory()->create(['role' => 'HR', 'is_approved' => true, 'hired_date' => now()->subYears(1)]);

        $this->actingAs($user)
            ->post(route('leave-requests.store'), $this->payload([
                'leave_type' => 'VL',
                'reason' => 'Vacation leave with supporting travel documents attached.',
                'medical_cert_files' => [UploadedFile::fake()->image('vl1.jpg')],
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $leaveRequest = LeaveRequest::firstOrFail();

        $this->assertSame('VL', $leaveRequest->leave_type);
        $this->assertSame(1, $leaveRequest->documents()->count());
        $this->assertTrue((bool) $leaveRequest->medical_cert_submitted);

        foreach ($leaveRequest->documents as $document) {
            Storage::disk('local')->assertExists($document->file_path);
        }
    }
}
