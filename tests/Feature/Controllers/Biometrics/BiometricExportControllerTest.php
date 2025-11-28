<?php

namespace Tests\Feature\Controllers\Biometrics;

use App\Models\BiometricRecord;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BiometricExportControllerTest extends TestCase
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
    public function it_displays_export_page()
    {
        $user = User::factory()->create();
        $site = Site::factory()->create();
        BiometricRecord::factory()->create([
            'user_id' => $user->id,
            'site_id' => $site->id,
        ]);

        $response = $this->actingAs($this->admin)->get(route('biometric-export.index'));

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Attendance/BiometricRecords/Export')
            ->has('users', 1)
            ->has('sites', 1)
        );
    }

    #[Test]
    public function it_exports_records_to_csv()
    {
        BiometricRecord::factory()->count(5)->create([
            'record_date' => now()->format('Y-m-d'),
        ]);

        $response = $this->actingAs($this->admin)->get(route('biometric-export.export', [
            'start_date' => now()->format('Y-m-d'),
            'end_date' => now()->format('Y-m-d'),
            'format' => 'csv',
        ]));

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $response->assertHeader('Content-Disposition', 'attachment; filename="biometric_export_' . now()->format('Y-m-d') . '_to_' . now()->format('Y-m-d') . '.csv"');
    }

    #[Test]
    public function it_exports_records_to_xlsx()
    {
        BiometricRecord::factory()->count(5)->create([
            'record_date' => now()->format('Y-m-d'),
        ]);

        $response = $this->actingAs($this->admin)->get(route('biometric-export.export', [
            'start_date' => now()->format('Y-m-d'),
            'end_date' => now()->format('Y-m-d'),
            'format' => 'xlsx',
        ]));

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->assertHeader('Content-Disposition', 'attachment; filename="biometric_export_' . now()->format('Y-m-d') . '_to_' . now()->format('Y-m-d') . '.xlsx"');
    }

    #[Test]
    public function it_filters_export_by_user_and_site()
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $site1 = Site::factory()->create();
        $site2 = Site::factory()->create();

        $date = now()->format('Y-m-d');

        BiometricRecord::factory()->create(['user_id' => $user1->id, 'site_id' => $site1->id, 'record_date' => $date]);
        BiometricRecord::factory()->create(['user_id' => $user2->id, 'site_id' => $site1->id, 'record_date' => $date]);
        BiometricRecord::factory()->create(['user_id' => $user1->id, 'site_id' => $site2->id, 'record_date' => $date]);

        // Filter for user1 and site1
        $response = $this->actingAs($this->admin)->get(route('biometric-export.export', [
            'start_date' => $date,
            'end_date' => $date,
            'format' => 'csv',
            'user_ids' => [$user1->id],
            'site_ids' => [$site1->id],
        ]));

        $response->assertStatus(200);
        $content = $response->getContent();

        // Should contain user1's name but not user2's
        $this->assertStringContainsString($user1->first_name, $content);
        $this->assertStringNotContainsString($user2->first_name, $content);

        // Should contain site1's name but not site2's
        $this->assertStringContainsString($site1->name, $content);
        $this->assertStringNotContainsString($site2->name, $content);
    }
}
