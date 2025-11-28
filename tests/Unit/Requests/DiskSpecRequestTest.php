<?php

declare(strict_types=1);

namespace Tests\Unit\Requests;

use App\Http\Requests\DiskSpecRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DiskSpecRequestTest extends TestCase
{
    #[Test]
    public function it_authorizes_all_users(): void
    {
        $request = new DiskSpecRequest();

        $this->assertTrue($request->authorize());
    }

    #[Test]
    public function it_validates_with_complete_create_data(): void
    {
        $request = DiskSpecRequest::create('/test', 'POST');

        $data = [
            'manufacturer' => 'Samsung',
            'model' => '870 EVO',
            'capacity_gb' => 500,
            'interface' => 'SATA III',
            'drive_type' => 'SSD',
            'sequential_read_mb' => 560,
            'sequential_write_mb' => 530,
            'stock_quantity' => 10,
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertFalse($validator->fails());
    }

    #[Test]
    public function it_requires_stock_quantity_on_post(): void
    {
        $request = DiskSpecRequest::create('/test', 'POST');

        $data = [
            'manufacturer' => 'Samsung',
            'model' => '870 EVO',
            'capacity_gb' => 500,
            'interface' => 'SATA III',
            'drive_type' => 'SSD',
            'sequential_read_mb' => 560,
            'sequential_write_mb' => 530,
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('stock_quantity', $validator->errors()->toArray());
    }

    #[Test]
    public function it_does_not_require_stock_quantity_on_put(): void
    {
        $request = DiskSpecRequest::create('/test', 'PUT');

        $data = [
            'manufacturer' => 'Samsung',
            'model' => '870 EVO',
            'capacity_gb' => 500,
            'interface' => 'SATA III',
            'drive_type' => 'SSD',
            'sequential_read_mb' => 560,
            'sequential_write_mb' => 530,
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertFalse($validator->fails());
    }

    #[Test]
    public function it_requires_all_fields(): void
    {
        $request = DiskSpecRequest::create('/test', 'POST');
        $data = [];

        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $errors = $validator->errors()->toArray();
        $this->assertArrayHasKey('manufacturer', $errors);
        $this->assertArrayHasKey('model', $errors);
        $this->assertArrayHasKey('capacity_gb', $errors);
        $this->assertArrayHasKey('interface', $errors);
        $this->assertArrayHasKey('drive_type', $errors);
        $this->assertArrayHasKey('sequential_read_mb', $errors);
        $this->assertArrayHasKey('sequential_write_mb', $errors);
    }

    #[Test]
    public function it_requires_capacity_to_be_at_least_1(): void
    {
        $request = DiskSpecRequest::create('/test', 'POST');

        $data = [
            'manufacturer' => 'Samsung',
            'model' => '870 EVO',
            'capacity_gb' => 0,
            'interface' => 'SATA III',
            'drive_type' => 'SSD',
            'sequential_read_mb' => 560,
            'sequential_write_mb' => 530,
            'stock_quantity' => 10,
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('capacity_gb', $validator->errors()->toArray());
    }

    #[Test]
    public function it_requires_speeds_to_be_at_least_1(): void
    {
        $request = DiskSpecRequest::create('/test', 'POST');

        $data = [
            'manufacturer' => 'Samsung',
            'model' => '870 EVO',
            'capacity_gb' => 500,
            'interface' => 'SATA III',
            'drive_type' => 'SSD',
            'sequential_read_mb' => 0,
            'sequential_write_mb' => 0,
            'stock_quantity' => 10,
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $errors = $validator->errors()->toArray();
        $this->assertArrayHasKey('sequential_read_mb', $errors);
        $this->assertArrayHasKey('sequential_write_mb', $errors);
    }

    #[Test]
    public function it_has_custom_attributes(): void
    {
        $request = new DiskSpecRequest();

        $attributes = $request->attributes();

        $this->assertEquals('capacity', $attributes['capacity_gb']);
        $this->assertEquals('sequential read speed', $attributes['sequential_read_mb']);
        $this->assertEquals('sequential write speed', $attributes['sequential_write_mb']);
        $this->assertEquals('initial stock quantity', $attributes['stock_quantity']);
    }

    #[Test]
    public function it_has_custom_messages(): void
    {
        $request = new DiskSpecRequest();

        $messages = $request->messages();

        $this->assertStringContainsString('at least 1 GB', $messages['capacity_gb.min']);
        $this->assertStringContainsString('at least 1 MB/s', $messages['sequential_read_mb.min']);
        $this->assertStringContainsString('at least 1 MB/s', $messages['sequential_write_mb.min']);
    }
}
