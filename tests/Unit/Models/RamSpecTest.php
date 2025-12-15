<?php

namespace Tests\Unit\Models;

use App\Models\PcSpec;
use App\Models\RamSpec;
use App\Models\Stock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RamSpecTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_has_fillable_attributes(): void
    {
        $ramSpec = RamSpec::create([
            'manufacturer' => 'Kingston',
            'model' => 'HyperX',
            'capacity_gb' => 16,
            'type' => 'DDR4',
            'speed' => 3200,
        ]);

        $this->assertEquals('Kingston', $ramSpec->manufacturer);
        $this->assertEquals('HyperX', $ramSpec->model);
        $this->assertEquals(16, $ramSpec->capacity_gb);
    }

    #[Test]
    public function it_casts_capacity_gb_to_integer(): void
    {
        $ramSpec = RamSpec::factory()->create(['capacity_gb' => '8']);

        $this->assertIsInt($ramSpec->capacity_gb);
        $this->assertEquals(8, $ramSpec->capacity_gb);
    }

    #[Test]
    public function it_casts_speed_to_integer(): void
    {
        $ramSpec = RamSpec::factory()->create(['speed' => '2400']);

        $this->assertIsInt($ramSpec->speed);
        $this->assertEquals(2400, $ramSpec->speed);
    }

    #[Test]
    public function it_belongs_to_many_pc_specs(): void
    {
        $ramSpec = RamSpec::factory()->create();
        $pcSpec = PcSpec::factory()->create();

        $pcSpec->ramSpecs()->attach($ramSpec->id, ['quantity' => 2]);

        $this->assertCount(1, $ramSpec->pcSpecs);
        $this->assertTrue($ramSpec->pcSpecs->contains($pcSpec));
    }

    #[Test]
    public function it_has_one_stock(): void
    {
        $ramSpec = RamSpec::factory()->create();

        Stock::create([
            'stockable_type' => RamSpec::class,
            'stockable_id' => $ramSpec->id,
            'quantity' => 50,
        ]);

        $this->assertInstanceOf(Stock::class, $ramSpec->stock);
        $this->assertEquals(50, $ramSpec->stock->quantity);
    }

    #[Test]
    public function it_searches_by_manufacturer(): void
    {
        RamSpec::factory()->create(['manufacturer' => 'Kingston']);
        RamSpec::factory()->create(['manufacturer' => 'Corsair']);

        $results = RamSpec::search('Kingston')->get();

        $this->assertCount(1, $results);
        $this->assertEquals('Kingston', $results->first()->manufacturer);
    }

    #[Test]
    public function it_searches_by_model(): void
    {
        RamSpec::factory()->create(['model' => 'HyperX Fury']);
        RamSpec::factory()->create(['model' => 'Vengeance']);

        $results = RamSpec::search('Fury')->get();

        $this->assertCount(1, $results);
        $this->assertStringContainsString('Fury', $results->first()->model);
    }

    #[Test]
    public function it_searches_by_type(): void
    {
        RamSpec::factory()->create(['type' => 'DDR4']);
        RamSpec::factory()->create(['type' => 'DDR5']);

        $results = RamSpec::search('DDR4')->get();

        $this->assertCount(1, $results);
    }

    #[Test]
    public function it_search_is_case_insensitive(): void
    {
        RamSpec::factory()->create(['manufacturer' => 'Kingston']);

        $results = RamSpec::search('kingston')->get();

        $this->assertCount(1, $results);
    }

    #[Test]
    public function it_returns_all_when_search_is_null(): void
    {
        RamSpec::factory()->count(3)->create();

        $results = RamSpec::search(null)->get();

        $this->assertCount(3, $results);
    }

    #[Test]
    public function it_stores_timestamps(): void
    {
        $ramSpec = RamSpec::factory()->create();

        $this->assertNotNull($ramSpec->created_at);
        $this->assertNotNull($ramSpec->updated_at);
    }
}
