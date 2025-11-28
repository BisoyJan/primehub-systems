<?php

namespace Tests\Feature\Controllers\Biometrics;

use App\Models\BiometricRecord;
use App\Models\BiometricRetentionPolicy;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BiometricRecordControllerTest extends TestCase
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
    public function it_displays_biometric_records_index_page()
    {
        BiometricRecord::factory()->count(3)->create();

        $response = $this->actingAs($this->admin)->get(route('biometric-records.index'));

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Attendance/BiometricRecords/Index')
            ->has('records.data', 3)
            ->has('stats')
            ->has('filters')
        );
    }

    #[Test]
    public function it_filters_biometric_records_by_user()
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        BiometricRecord::factory()->create(['user_id' => $user1->id]);
        BiometricRecord::factory()->create(['user_id' => $user2->id]);

        $response = $this->actingAs($this->admin)->get(route('biometric-records.index', ['user_id' => $user1->id]));

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Attendance/BiometricRecords/Index')
            ->has('records.data', 1)
            ->where('records.data.0.user_id', $user1->id)
        );
    }

    #[Test]
    public function it_filters_biometric_records_by_site()
    {
        $site1 = Site::factory()->create();
        $site2 = Site::factory()->create();

        BiometricRecord::factory()->create(['site_id' => $site1->id]);
        BiometricRecord::factory()->create(['site_id' => $site2->id]);

        $response = $this->actingAs($this->admin)->get(route('biometric-records.index', ['site_id' => $site1->id]));

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Attendance/BiometricRecords/Index')
            ->has('records.data', 1)
            ->where('records.data.0.site_id', $site1->id)
        );
    }

    #[Test]
    public function it_filters_biometric_records_by_date_range()
    {
        BiometricRecord::factory()->create(['record_date' => '2023-01-01']);
        BiometricRecord::factory()->create(['record_date' => '2023-01-15']);
        BiometricRecord::factory()->create(['record_date' => '2023-02-01']);

        $response = $this->actingAs($this->admin)->get(route('biometric-records.index', [
            'date_from' => '2023-01-01',
            'date_to' => '2023-01-31',
        ]));

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Attendance/BiometricRecords/Index')
            ->has('records.data', 2)
        );
    }

    #[Test]
    public function it_displays_biometric_record_details_page()
    {
        $user = User::factory()->create();
        $date = '2023-01-01';
        BiometricRecord::factory()->count(2)->create([
            'user_id' => $user->id,
            'record_date' => $date,
        ]);

        $response = $this->actingAs($this->admin)->get(route('biometric-records.show', [
            'user' => $user->id,
            'date' => $date,
        ]));

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Attendance/BiometricRecords/Show')
            ->where('user.id', $user->id)
            ->where('date', $date)
            ->has('records', 2)
        );
    }

    #[Test]
    public function it_calculates_statistics_correctly()
    {
        // Create records for today, this week, this month
        BiometricRecord::factory()->create(['record_date' => Carbon::today()]);
        BiometricRecord::factory()->create(['record_date' => Carbon::now()->startOfWeek()]);
        BiometricRecord::factory()->create(['record_date' => Carbon::now()->startOfMonth()]);

        // Create old record eligible for cleanup
        $site = Site::factory()->create();
        BiometricRetentionPolicy::create([
            'name' => 'Test Policy',
            'retention_months' => 1,
            'applies_to_type' => 'site',
            'applies_to_id' => $site->id,
            'is_active' => true,
            'priority' => 1,
        ]);
        BiometricRecord::factory()->create([
            'site_id' => $site->id,
            'record_date' => Carbon::now()->subMonths(2),
        ]);

        $response = $this->actingAs($this->admin)->get(route('biometric-records.index'));

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->where('stats.total', 4)
            ->where('stats.old_records', 1)
        );
    }
}
