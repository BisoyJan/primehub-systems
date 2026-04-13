<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\EmployeeSchedule;
use App\Models\PcSpec;
use App\Models\ProcessorSpec;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests for PC Spec CRUD operations.
 */
class PcSpecCrudTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected ProcessorSpec $processor;

    protected function setUp(): void
    {
        parent::setUp();

        // IT role has pcspecs permissions
        $this->admin = User::factory()->create([
            'role' => 'IT',
            'is_approved' => true,
        ]);

        $this->processor = ProcessorSpec::factory()->create();
    }

    #[Test]
    public function it_displays_pc_specs_index_page()
    {
        $pcSpecs = PcSpec::factory()->count(5)->create();

        $response = $this->actingAs($this->admin)
            ->get(route('pcspecs.index'));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Computer/PcSpecs/Index')
                ->has('pcspecs.data', 5)
            );
    }

    #[Test]
    public function it_displays_create_pc_spec_form()
    {
        $response = $this->actingAs($this->admin)
            ->get(route('pcspecs.create'));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Computer/PcSpecs/Create')
                ->has('processorOptions')
            );
    }

    #[Test]
    public function it_creates_single_pc_spec()
    {
        $data = [
            'manufacturer' => 'ASUS',
            'model' => 'PRIME B450M-A',
            'memory_type' => 'DDR4',
            'ram_gb' => 32,
            'disk_gb' => 512,
            'available_ports' => 'USB 3.0 x4, HDMI x1',
            'processor_mode' => 'existing',
            'processor_spec_id' => $this->processor->id,
            'quantity' => 1,
        ];

        $response = $this->actingAs($this->admin)
            ->post(route('pcspecs.store'), $data);

        $response->assertRedirect(route('pcspecs.index'));

        $this->assertDatabaseHas('pc_specs', [
            'manufacturer' => 'ASUS',
            'model' => 'PRIME B450M-A',
            'memory_type' => 'DDR4',
            'ram_gb' => 32,
            'disk_gb' => 512,
            'available_ports' => 'USB 3.0 x4, HDMI x1',
        ]);

        $pcSpec = PcSpec::first();
        $this->assertCount(1, $pcSpec->processorSpecs()->get());
    }

    #[Test]
    public function it_creates_multiple_pc_specs_with_quantity()
    {
        $data = [
            'manufacturer' => 'Gigabyte',
            'model' => 'B550M DS3H',
            'memory_type' => 'DDR4',
            'ram_gb' => 16,
            'disk_gb' => 256,
            'processor_mode' => 'existing',
            'processor_spec_id' => $this->processor->id,
            'quantity' => 3,
        ];

        $response = $this->actingAs($this->admin)
            ->post(route('pcspecs.store'), $data);

        $response->assertRedirect(route('pcspecs.index'));

        $this->assertEquals(3, PcSpec::count());
    }

    #[Test]
    public function it_validates_required_fields_on_create()
    {
        $response = $this->actingAs($this->admin)
            ->post(route('pcspecs.store'), []);

        $response->assertSessionHasErrors([
            'manufacturer',
            'model',
            'memory_type',
        ]);
    }

    #[Test]
    public function it_displays_edit_pc_spec_form()
    {
        $pcSpec = PcSpec::factory()->create();
        $pcSpec->processorSpecs()->attach($this->processor->id);

        $response = $this->actingAs($this->admin)
            ->get(route('pcspecs.edit', $pcSpec));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Computer/PcSpecs/Edit')
                ->where('pcspec.id', $pcSpec->id)
                ->has('processorOptions')
            );
    }

    #[Test]
    public function it_updates_pc_spec_basic_info()
    {
        $pcSpec = PcSpec::factory()->create([
            'manufacturer' => 'ASUS',
            'model' => 'OLD-MODEL',
        ]);
        $pcSpec->processorSpecs()->attach($this->processor->id);

        $response = $this->actingAs($this->admin)
            ->put(route('pcspecs.update', $pcSpec), [
                'manufacturer' => 'ASUS',
                'model' => 'NEW-MODEL',
                'memory_type' => $pcSpec->memory_type,
                'ram_gb' => 16,
                'disk_gb' => 512,
                'processor_mode' => 'existing',
                'processor_spec_id' => $this->processor->id,
            ]);

        $response->assertRedirect(route('pcspecs.index'));

        $this->assertDatabaseHas('pc_specs', [
            'id' => $pcSpec->id,
            'model' => 'NEW-MODEL',
            'ram_gb' => 16,
            'disk_gb' => 512,
        ]);
    }

    #[Test]
    public function it_deletes_pc_spec()
    {
        $pcSpec = PcSpec::factory()->create();
        $pcSpec->processorSpecs()->attach($this->processor->id);

        $response = $this->actingAs($this->admin)
            ->delete(route('pcspecs.destroy', $pcSpec));

        $response->assertRedirect(route('pcspecs.index'));

        $this->assertDatabaseMissing('pc_specs', ['id' => $pcSpec->id]);
    }

    #[Test]
    public function it_displays_pc_spec_details()
    {
        $pcSpec = PcSpec::factory()->create([
            'ram_gb' => 32,
            'disk_gb' => 1024,
        ]);
        $pcSpec->processorSpecs()->attach($this->processor->id);

        $response = $this->actingAs($this->admin)
            ->get(route('pcspecs.show', $pcSpec));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Computer/PcSpecs/Show')
                ->where('pcspec.id', $pcSpec->id)
                ->has('pcspec.processor_specs', 1)
            );
    }

    #[Test]
    public function it_filters_pc_specs_by_search()
    {
        PcSpec::factory()->create(['model' => 'PRIME B450M']);
        PcSpec::factory()->create(['model' => 'ROG STRIX B550']);
        PcSpec::factory()->create(['pc_number' => 'PC-2024-001']);

        $response = $this->actingAs($this->admin)
            ->get(route('pcspecs.index', ['search' => 'PRIME']));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Computer/PcSpecs/Index')
                ->has('pcspecs.data', 1)
                ->where('pcspecs.data.0.model', 'PRIME B450M')
            );
    }

    #[Test]
    public function unauthorized_users_cannot_create_pc_specs()
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

        $response = $this->actingAs($user)
            ->post(route('pcspecs.store'), [
                'manufacturer' => 'ASUS',
                'model' => 'TEST',
                'memory_type' => 'DDR4',
                'ram_gb' => 16,
                'disk_gb' => 512,
                'processor_spec_id' => $this->processor->id,
            ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('pc_specs', ['manufacturer' => 'ASUS', 'model' => 'TEST']);
    }

    #[Test]
    public function unauthorized_users_cannot_delete_pc_specs()
    {
        $pcSpec = PcSpec::factory()->create();
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

        $response = $this->actingAs($user)
            ->delete(route('pcspecs.destroy', $pcSpec));

        $response->assertForbidden();
        $this->assertDatabaseHas('pc_specs', ['id' => $pcSpec->id]);
    }
}
