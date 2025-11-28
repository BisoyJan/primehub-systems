<?php

declare(strict_types=1);

namespace Tests\Unit\Requests;

use App\Http\Requests\RamSpecRequest;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RamSpecRequestTest extends TestCase
{
    #[Test]
    public function it_authorizes_all_users(): void
    {
        $request = new RamSpecRequest();

        $this->assertTrue($request->authorize());
    }

    #[Test]
    public function it_validates_with_complete_create_data(): void
    {
        $request = RamSpecRequest::create('/test', 'POST');

        $data = [
            'manufacturer' => 'Corsair',
            'model' => 'Vengeance LPX',
            'capacity_gb' => 8,
            'type' => 'DDR4',
            'speed' => 3200,
            'form_factor' => 'DIMM',
            'voltage' => 1.35,
            'stock_quantity' => 50,
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertFalse($validator->fails());
    }

    #[Test]
    public function it_requires_stock_quantity_on_post(): void
    {
        $request = RamSpecRequest::create('/test', 'POST');

        $data = [
            'manufacturer' => 'Corsair',
            'model' => 'Vengeance LPX',
            'capacity_gb' => 8,
            'type' => 'DDR4',
            'speed' => 3200,
            'form_factor' => 'DIMM',
            'voltage' => 1.35,
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('stock_quantity', $validator->errors()->toArray());
    }

    #[Test]
    public function it_does_not_require_stock_quantity_on_put(): void
    {
        $request = RamSpecRequest::create('/test', 'PUT');

        $data = [
            'manufacturer' => 'Corsair',
            'model' => 'Vengeance LPX',
            'capacity_gb' => 8,
            'type' => 'DDR4',
            'speed' => 3200,
            'form_factor' => 'DIMM',
            'voltage' => 1.35,
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertFalse($validator->fails());
    }

    #[Test]
    public function it_requires_all_fields(): void
    {
        $request = RamSpecRequest::create('/test', 'POST');
        $data = [];

        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $errors = $validator->errors()->toArray();
        $this->assertArrayHasKey('manufacturer', $errors);
        $this->assertArrayHasKey('model', $errors);
        $this->assertArrayHasKey('capacity_gb', $errors);
        $this->assertArrayHasKey('type', $errors);
        $this->assertArrayHasKey('speed', $errors);
        $this->assertArrayHasKey('form_factor', $errors);
        $this->assertArrayHasKey('voltage', $errors);
    }

    #[Test]
    public function it_requires_capacity_to_be_at_least_1(): void
    {
        $request = RamSpecRequest::create('/test', 'POST');

        $data = [
            'manufacturer' => 'Corsair',
            'model' => 'Vengeance LPX',
            'capacity_gb' => 0,
            'type' => 'DDR4',
            'speed' => 3200,
            'form_factor' => 'DIMM',
            'voltage' => 1.35,
            'stock_quantity' => 50,
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('capacity_gb', $validator->errors()->toArray());
    }

    #[Test]
    public function it_requires_speed_to_be_at_least_1(): void
    {
        $request = RamSpecRequest::create('/test', 'POST');

        $data = [
            'manufacturer' => 'Corsair',
            'model' => 'Vengeance LPX',
            'capacity_gb' => 8,
            'type' => 'DDR4',
            'speed' => 0,
            'form_factor' => 'DIMM',
            'voltage' => 1.35,
            'stock_quantity' => 50,
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('speed', $validator->errors()->toArray());
    }

    #[Test]
    public function it_requires_voltage_to_be_greater_than_zero(): void
    {
        $request = RamSpecRequest::create('/test', 'POST');

        $data = [
            'manufacturer' => 'Corsair',
            'model' => 'Vengeance LPX',
            'capacity_gb' => 8,
            'type' => 'DDR4',
            'speed' => 3200,
            'form_factor' => 'DIMM',
            'voltage' => -1,
            'stock_quantity' => 50,
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('voltage', $validator->errors()->toArray());
    }

    #[Test]
    public function it_accepts_decimal_voltages(): void
    {
        $request = RamSpecRequest::create('/test', 'POST');

        $data = [
            'manufacturer' => 'Corsair',
            'model' => 'Vengeance LPX',
            'capacity_gb' => 8,
            'type' => 'DDR4',
            'speed' => 3200,
            'form_factor' => 'DIMM',
            'voltage' => 1.35,
            'stock_quantity' => 50,
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertFalse($validator->fails());
    }

    #[Test]
    public function it_has_custom_attributes(): void
    {
        $request = new RamSpecRequest();

        $attributes = $request->attributes();

        $this->assertEquals('capacity', $attributes['capacity_gb']);
        $this->assertEquals('form factor', $attributes['form_factor']);
        $this->assertEquals('initial stock quantity', $attributes['stock_quantity']);
    }

    #[Test]
    public function it_has_custom_messages(): void
    {
        $request = new RamSpecRequest();

        $messages = $request->messages();

        $this->assertStringContainsString('at least 1 GB', $messages['capacity_gb.min']);
        $this->assertStringContainsString('at least 1 MHz', $messages['speed.min']);
        $this->assertStringContainsString('greater than 0', $messages['voltage.min']);
    }
}
