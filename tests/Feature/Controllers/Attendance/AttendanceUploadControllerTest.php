<?php

namespace Tests\Feature\Controllers\Attendance;

use App\Models\AttendanceUpload;
use App\Models\Site;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AttendanceUploadControllerTest extends TestCase
{
    use RefreshDatabase;

    protected $admin;
    protected $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'Admin',
            'is_approved' => true,
            'approved_at' => now(),
        ]);

        $this->agent = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
            'approved_at' => now(),
        ]);
    }

    #[Test]
    public function it_displays_attendance_uploads_index_page()
    {
        AttendanceUpload::factory()->count(3)->create();

        $response = $this->actingAs($this->admin)->get(route('attendance-uploads.index'));

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Attendance/Uploads/Index')
            ->has('uploads.data', 3)
        );
    }

    #[Test]
    public function it_displays_attendance_upload_details_page()
    {
        $site = Site::factory()->create();
        $upload = AttendanceUpload::factory()->create([
            'biometric_site_id' => $site->id,
            'uploaded_by' => $this->admin->id,
            'unmatched_names_list' => ['John Doe', 'Jane Smith'],
            'dates_found' => ['2023-01-01', '2023-01-02'],
        ]);

        $response = $this->actingAs($this->admin)->get(route('attendance-uploads.show', $upload));

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Attendance/Uploads/Show')
            ->where('upload.id', $upload->id)
            ->where('upload.original_filename', $upload->original_filename)
            ->where('upload.biometric_site.id', $site->id)
            ->where('upload.uploaded_by.id', $this->admin->id)
            ->has('upload.unmatched_names_list', 2)
            ->has('upload.dates_found', 2)
        );
    }

    #[Test]
    public function it_allows_agents_to_view_uploads_index()
    {
        AttendanceUpload::factory()->count(1)->create();

        $response = $this->actingAs($this->agent)->get(route('attendance-uploads.index'));

        $response->assertStatus(200);
    }

    #[Test]
    public function it_allows_agents_to_view_upload_details()
    {
        $upload = AttendanceUpload::factory()->create();

        $response = $this->actingAs($this->agent)->get(route('attendance-uploads.show', $upload));

        $response->assertStatus(200);
    }

    #[Test]
    public function it_redirects_unauthenticated_users()
    {
        $response = $this->get(route('attendance-uploads.index'));
        $response->assertRedirect(route('login'));

        $upload = AttendanceUpload::factory()->create();
        $response = $this->get(route('attendance-uploads.show', $upload));
        $response->assertRedirect(route('login'));
    }
}
