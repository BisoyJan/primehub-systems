<?php

namespace Tests\Feature\Controllers\Hardware;

use App\Models\ProcessorSpec;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProcessorSpecsControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create([
            'role' => 'IT',
            'is_approved' => true,
        ]));
    }

    public function test_index_displays_processor_specs()
    {
        ProcessorSpec::factory()->count(3)->create();

        $this->get(route('processorspecs.index'))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Computer/ProcessorSpecs/Index')
                ->has('processorspecs.data', 3)
            );
    }

    public function test_store_creates_processor_spec()
    {
        $data = [
            'manufacturer' => 'Intel',
            'model' => 'Core i5-12400',
            'core_count' => 6,
            'thread_count' => 12,
            'base_clock_ghz' => 2.50,
            'boost_clock_ghz' => 4.40,
            'stock_quantity' => 5,
        ];

        $this->post(route('processorspecs.store'), $data)
            ->assertRedirect(route('processorspecs.index'))
            ->assertSessionHas('message')
            ->assertSessionHas('type', 'success');

        unset($data['stock_quantity']);
        // Float comparison might be tricky, but database stores as decimal(4,2)
        // 2.50 might be stored as "2.50" string or 2.5 float.
        // assertDatabaseHas checks for equality.
        $this->assertDatabaseHas('processor_specs', $data);
    }

    public function test_update_updates_processor_spec()
    {
        $processorSpec = ProcessorSpec::factory()->create();

        $data = [
            'manufacturer' => 'AMD',
            'model' => 'Ryzen 5 5600X',
            'core_count' => 6,
            'thread_count' => 12,
            'base_clock_ghz' => 3.70,
            'boost_clock_ghz' => 4.60,
        ];

        $this->put(route('processorspecs.update', $processorSpec), $data)
            ->assertRedirect(route('processorspecs.index'))
            ->assertSessionHas('message')
            ->assertSessionHas('type', 'success');

        $this->assertDatabaseHas('processor_specs', array_merge(['id' => $processorSpec->id], $data));
    }

    public function test_destroy_deletes_processor_spec()
    {
        $processorSpec = ProcessorSpec::factory()->create();

        $this->delete(route('processorspecs.destroy', $processorSpec))
            ->assertRedirect(route('processorspecs.index'))
            ->assertSessionHas('message')
            ->assertSessionHas('type', 'success');

        $this->assertDatabaseMissing('processor_specs', ['id' => $processorSpec->id]);
    }
}
