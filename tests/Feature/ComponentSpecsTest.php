<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\EmployeeSchedule;
use App\Models\ProcessorSpec;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests for Component Specs (Processor) functionality.
 *
 * Note: These tests are marked as duplicates because comprehensive tests exist in:
 * - tests/Feature/Controllers/Hardware/ProcessorSpecsControllerTest.php
 *
 * RamSpec, DiskSpec, and MonitorSpec models have been removed.
 */
#[Group('duplicate')]
class ComponentSpecsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // IT role has hardware permissions including processor_specs
        $this->admin = User::factory()->create([
            'role' => 'IT',
            'is_approved' => true,
        ]);
    }

    // ==================== PROCESSOR SPECS TESTS ====================

    #[Test]
    public function it_displays_processor_specs_index()
    {
        ProcessorSpec::factory()->count(3)->create();

        $response = $this->actingAs($this->admin)
            ->get(route('processorspecs.index'));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Computer/ProcessorSpecs/Index')
                // Controller uses 'processorspecs' not 'processorSpecs'
                ->has('processorspecs.data', 3)
            );
    }

    #[Test]
    public function it_creates_processor_spec()
    {
        $data = [
            'manufacturer' => 'Intel',
            'model' => 'Core i7-12700K',
            'core_count' => 12,
            'thread_count' => 20,
            'base_clock_ghz' => 3.6,
            'boost_clock_ghz' => 5.0,
        ];

        $response = $this->actingAs($this->admin)
            ->post(route('processorspecs.store'), $data);

        $response->assertRedirect(route('processorspecs.index'));

        $this->assertDatabaseHas('processor_specs', [
            'manufacturer' => 'Intel',
            'model' => 'Core i7-12700K',
            'core_count' => 12,
        ]);
    }

    #[Test]
    public function it_updates_processor_spec()
    {
        $processorSpec = ProcessorSpec::factory()->create([
            'model' => 'OLD-CPU',
            'core_count' => 6,
        ]);

        $response = $this->actingAs($this->admin)
            ->put(route('processorspecs.update', $processorSpec), [
                'manufacturer' => $processorSpec->manufacturer,
                'model' => 'NEW-CPU',
                'core_count' => 8,
                'thread_count' => $processorSpec->thread_count,
                'base_clock_ghz' => $processorSpec->base_clock_ghz,
                'boost_clock_ghz' => $processorSpec->boost_clock_ghz,
            ]);

        $response->assertRedirect(route('processorspecs.index'));

        $this->assertDatabaseHas('processor_specs', [
            'id' => $processorSpec->id,
            'model' => 'NEW-CPU',
            'core_count' => 8,
        ]);
    }

    #[Test]
    public function it_deletes_processor_spec()
    {
        $processorSpec = ProcessorSpec::factory()->create();

        $response = $this->actingAs($this->admin)
            ->delete(route('processorspecs.destroy', $processorSpec));

        $response->assertRedirect(route('processorspecs.index'));
        $this->assertDatabaseMissing('processor_specs', ['id' => $processorSpec->id]);
    }

    #[Test]
    public function unauthorized_users_cannot_manage_component_specs()
    {
        $user = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
        ]);

        // Agent needs EmployeeSchedule to avoid redirect to /schedule-setup
        $site = Site::factory()->create();
        $campaign = Campaign::factory()->create();
        EmployeeSchedule::factory()->create([
            'user_id' => $user->id,
            'site_id' => $site->id,
            'campaign_id' => $campaign->id,
        ]);

        // Test Processor
        $response = $this->actingAs($user)
            ->post(route('processorspecs.store'), [
                'manufacturer' => 'Test',
                'model' => 'Test',
                'core_count' => 8,
                'thread_count' => 16,
                'base_clock_ghz' => 3.0,
                'boost_clock_ghz' => 4.5,
            ]);
        $response->assertForbidden();
    }
}
