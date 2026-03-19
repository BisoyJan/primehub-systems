<?php

namespace Tests\Feature\Controllers\Coaching;

use App\Models\Campaign;
use App\Models\CoachingSession;
use App\Models\EmployeeSchedule;
use App\Models\Notification;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CoachingSessionAttachmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->mock(NotificationService::class, function ($mock) {
            $mock->shouldReceive('notifyCoachingSessionCreated')
                ->andReturn(\Mockery::mock(Notification::class));
        });
    }

    protected function createTeamWithCampaign(): array
    {
        $campaign = Campaign::factory()->create();

        $teamLead = User::factory()->create(['role' => 'Team Lead', 'is_approved' => true]);
        EmployeeSchedule::factory()->create([
            'user_id' => $teamLead->id,
            'campaign_id' => $campaign->id,
            'is_active' => true,
        ]);

        $agent = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);
        EmployeeSchedule::factory()->create([
            'user_id' => $agent->id,
            'campaign_id' => $campaign->id,
            'is_active' => true,
        ]);

        return compact('campaign', 'teamLead', 'agent');
    }

    protected function validSessionData(int $coacheeId): array
    {
        return [
            'coachee_id' => $coacheeId,
            'session_date' => now()->format('Y-m-d'),
            'purpose' => 'performance_behavior_issue',
            'performance_description' => 'Test performance description.',
            'smart_action_plan' => 'Test action plan.',
            'profile_new_hire' => false,
            'profile_tenured' => true,
            'profile_returning' => false,
            'profile_previously_coached_same_issue' => false,
            'focus_attendance_tardiness' => true,
            'focus_productivity' => false,
            'focus_compliance' => false,
            'focus_callouts' => false,
            'focus_recognition_milestones' => false,
            'focus_growth_development' => false,
            'focus_other' => false,
            'root_cause_lack_of_skills' => false,
            'root_cause_lack_of_clarity' => false,
            'root_cause_personal_issues' => true,
            'root_cause_motivation_engagement' => false,
            'root_cause_health_fatigue' => false,
            'root_cause_workload_process' => false,
            'root_cause_peer_conflict' => false,
            'root_cause_others' => false,
        ];
    }

    // ─── Store with Attachments ─────────────────────────────────────

    #[Test]
    public function store_creates_session_with_attachments(): void
    {
        $team = $this->createTeamWithCampaign();
        $data = $this->validSessionData($team['agent']->id);
        $data['attachments'] = [
            UploadedFile::fake()->image('photo1.jpg', 800, 600),
            UploadedFile::fake()->image('photo2.png', 400, 300),
        ];

        $response = $this->actingAs($team['teamLead'])
            ->post(route('coaching.sessions.store'), $data);

        $response->assertRedirect(route('coaching.sessions.index'));

        $session = CoachingSession::first();
        $this->assertNotNull($session);
        $this->assertCount(2, $session->attachments);

        foreach ($session->attachments as $attachment) {
            Storage::disk('local')->assertExists($attachment->file_path);
            $this->assertNotEmpty($attachment->original_filename);
            $this->assertNotEmpty($attachment->mime_type);
            $this->assertGreaterThan(0, $attachment->file_size);
        }
    }

    #[Test]
    public function store_works_without_attachments(): void
    {
        $team = $this->createTeamWithCampaign();
        $data = $this->validSessionData($team['agent']->id);

        $response = $this->actingAs($team['teamLead'])
            ->post(route('coaching.sessions.store'), $data);

        $response->assertRedirect(route('coaching.sessions.index'));

        $session = CoachingSession::first();
        $this->assertNotNull($session);
        $this->assertCount(0, $session->attachments);
    }

    #[Test]
    public function store_rejects_more_than_10_attachments(): void
    {
        $team = $this->createTeamWithCampaign();
        $data = $this->validSessionData($team['agent']->id);
        $data['attachments'] = array_map(
            fn () => UploadedFile::fake()->image('photo.jpg', 100, 100),
            range(1, 11)
        );

        $response = $this->actingAs($team['teamLead'])
            ->post(route('coaching.sessions.store'), $data);

        $response->assertSessionHasErrors('attachments');
    }

    #[Test]
    public function store_rejects_non_image_files(): void
    {
        $team = $this->createTeamWithCampaign();
        $data = $this->validSessionData($team['agent']->id);
        $data['attachments'] = [
            UploadedFile::fake()->create('document.pdf', 100, 'application/pdf'),
        ];

        $response = $this->actingAs($team['teamLead'])
            ->post(route('coaching.sessions.store'), $data);

        $response->assertSessionHasErrors('attachments.0');
    }

    #[Test]
    public function store_rejects_files_over_4mb(): void
    {
        $team = $this->createTeamWithCampaign();
        $data = $this->validSessionData($team['agent']->id);
        $data['attachments'] = [
            UploadedFile::fake()->image('large.jpg')->size(5000),
        ];

        $response = $this->actingAs($team['teamLead'])
            ->post(route('coaching.sessions.store'), $data);

        $response->assertSessionHasErrors('attachments.0');
    }

    // ─── Update with Attachments ────────────────────────────────────

    #[Test]
    public function update_adds_new_attachments(): void
    {
        $team = $this->createTeamWithCampaign();
        $session = CoachingSession::factory()->create([
            'coachee_id' => $team['agent']->id,
            'coach_id' => $team['teamLead']->id,
        ]);

        $data = $this->validSessionData($team['agent']->id);
        $data['attachments'] = [
            UploadedFile::fake()->image('new_photo.jpg', 800, 600),
        ];

        $response = $this->actingAs($team['teamLead'])
            ->put(route('coaching.sessions.update', $session), $data);

        $response->assertRedirect(route('coaching.sessions.show', $session));
        $this->assertCount(1, $session->fresh()->attachments);
    }

    #[Test]
    public function update_removes_existing_attachments(): void
    {
        $team = $this->createTeamWithCampaign();
        $session = CoachingSession::factory()->create([
            'coachee_id' => $team['agent']->id,
            'coach_id' => $team['teamLead']->id,
        ]);

        // Create an existing attachment
        $file = UploadedFile::fake()->image('existing.jpg', 400, 300);
        $path = $file->storeAs('coaching_attachments', 'existing.jpg', 'local');
        $attachment = $session->attachments()->create([
            'file_path' => $path,
            'original_filename' => 'existing.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => $file->getSize(),
        ]);

        $data = $this->validSessionData($team['agent']->id);
        $data['removed_attachments'] = [$attachment->id];

        $response = $this->actingAs($team['teamLead'])
            ->put(route('coaching.sessions.update', $session), $data);

        $response->assertRedirect(route('coaching.sessions.show', $session));
        $this->assertCount(0, $session->fresh()->attachments);
        Storage::disk('local')->assertMissing($path);
    }

    // ─── View Attachment ────────────────────────────────────────────

    #[Test]
    public function authorized_user_can_view_attachment(): void
    {
        $team = $this->createTeamWithCampaign();
        $session = CoachingSession::factory()->create([
            'coachee_id' => $team['agent']->id,
            'coach_id' => $team['teamLead']->id,
        ]);

        $file = UploadedFile::fake()->image('test.jpg', 400, 300);
        $path = $file->storeAs('coaching_attachments', 'test.jpg', 'local');
        $attachment = $session->attachments()->create([
            'file_path' => $path,
            'original_filename' => 'test.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => $file->getSize(),
        ]);

        $response = $this->actingAs($team['teamLead'])
            ->get(route('coaching.sessions.attachment', [$session, $attachment]));

        $response->assertStatus(200);
    }

    #[Test]
    public function attachment_from_different_session_returns_404(): void
    {
        $team = $this->createTeamWithCampaign();
        $session1 = CoachingSession::factory()->create([
            'coachee_id' => $team['agent']->id,
            'coach_id' => $team['teamLead']->id,
        ]);
        $session2 = CoachingSession::factory()->create([
            'coachee_id' => $team['agent']->id,
            'coach_id' => $team['teamLead']->id,
        ]);

        $file = UploadedFile::fake()->image('test.jpg', 400, 300);
        $path = $file->storeAs('coaching_attachments', 'test.jpg', 'local');
        $attachment = $session1->attachments()->create([
            'file_path' => $path,
            'original_filename' => 'test.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => $file->getSize(),
        ]);

        // Try to access session1's attachment via session2's URL
        $response = $this->actingAs($team['teamLead'])
            ->get(route('coaching.sessions.attachment', [$session2, $attachment]));

        $response->assertStatus(404);
    }

    // ─── Destroy Cleans Up Attachments ──────────────────────────────

    #[Test]
    public function destroy_deletes_attachment_files_from_storage(): void
    {
        $team = $this->createTeamWithCampaign();
        $session = CoachingSession::factory()->create([
            'coachee_id' => $team['agent']->id,
            'coach_id' => $team['teamLead']->id,
        ]);

        $file = UploadedFile::fake()->image('test.jpg', 400, 300);
        $path = $file->storeAs('coaching_attachments', 'test.jpg', 'local');
        $session->attachments()->create([
            'file_path' => $path,
            'original_filename' => 'test.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => $file->getSize(),
        ]);

        // Admin can delete
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);

        $response = $this->actingAs($admin)
            ->delete(route('coaching.sessions.destroy', $session));

        $response->assertRedirect(route('coaching.sessions.index'));
        Storage::disk('local')->assertMissing($path);
        $this->assertDatabaseMissing('coaching_session_attachments', ['coaching_session_id' => $session->id]);
    }

    // ─── Show Includes Attachments ──────────────────────────────────

    #[Test]
    public function show_page_includes_attachments_data(): void
    {
        $team = $this->createTeamWithCampaign();
        $session = CoachingSession::factory()->create([
            'coachee_id' => $team['agent']->id,
            'coach_id' => $team['teamLead']->id,
        ]);

        $file = UploadedFile::fake()->image('test.jpg', 400, 300);
        $path = $file->storeAs('coaching_attachments', 'test.jpg', 'local');
        $session->attachments()->create([
            'file_path' => $path,
            'original_filename' => 'test.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => $file->getSize(),
        ]);

        $response = $this->actingAs($team['teamLead'])
            ->get(route('coaching.sessions.show', $session));

        $response->assertStatus(200)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Coaching/Sessions/Show')
                ->has('session.attachments', 1)
            );
    }
}
