<?php

declare(strict_types=1);

namespace Tests\Unit\Requests;

use App\Http\Requests\ProcessorSpecRequest;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProcessorSpecRequestTest extends TestCase
{
    #[Test]
    public function it_authorizes_all_users(): void
    {
        $request = new ProcessorSpecRequest();

        $this->assertTrue($request->authorize());
    }

    #[Test]
    public function it_validates_with_complete_create_data(): void
    {
        $request = ProcessorSpecRequest::create('/test', 'POST');

        $data = [
            'manufacturer' => 'Intel',
            'model' => 'Core i7-12700K',
            'core_count' => 12,
            'thread_count' => 20,
            'base_clock_ghz' => 3.6,
            'boost_clock_ghz' => 5.0,
            'stock_quantity' => 25,
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertFalse($validator->fails());
    }

    #[Test]
    public function it_requires_stock_quantity_on_post(): void
    {
        $request = ProcessorSpecRequest::create('/test', 'POST');

        $data = [
            'manufacturer' => 'Intel',
            'model' => 'Core i7-12700K',
            'core_count' => 12,
            'thread_count' => 20,
            'base_clock_ghz' => 3.6,
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('stock_quantity', $validator->errors()->toArray());
    }

    #[Test]
    public function it_does_not_require_stock_quantity_on_put(): void
    {
        $request = ProcessorSpecRequest::create('/test', 'PUT');

        $data = [
            'manufacturer' => 'Intel',
            'model' => 'Core i7-12700K',
            'core_count' => 12,
            'thread_count' => 20,
            'base_clock_ghz' => 3.6,
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertFalse($validator->fails());
    }

    #[Test]
    public function it_allows_nullable_optional_fields(): void
    {
        $request = ProcessorSpecRequest::create('/test', 'POST');

        $data = [
            'manufacturer' => 'AMD',
            'model' => 'Ryzen 5 5600X',
            'core_count' => 6,
            'thread_count' => 12,
            'base_clock_ghz' => 3.7,
            'boost_clock_ghz' => null,
            'stock_quantity' => 30,
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertFalse($validator->fails());
    }

    #[Test]
    public function it_requires_core_count_to_be_at_least_1(): void
    {
        $request = ProcessorSpecRequest::create('/test', 'POST');

        $data = [
            'manufacturer' => 'Intel',
            'model' => 'Core i7-12700K',
            'core_count' => 0,
            'thread_count' => 20,
            'base_clock_ghz' => 3.6,
            'stock_quantity' => 25,
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('core_count', $validator->errors()->toArray());
    }

    #[Test]
    public function it_requires_thread_count_to_be_at_least_1(): void
    {
        $request = ProcessorSpecRequest::create('/test', 'POST');

        $data = [
            'manufacturer' => 'Intel',
            'model' => 'Core i7-12700K',
            'core_count' => 12,
            'thread_count' => 0,
            'base_clock_ghz' => 3.6,
            'stock_quantity' => 25,
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('thread_count', $validator->errors()->toArray());
    }

    #[Test]
    public function it_requires_base_clock_to_be_greater_than_zero(): void
    {
        $request = ProcessorSpecRequest::create('/test', 'POST');

        $data = [
            'manufacturer' => 'Intel',
            'model' => 'Core i7-12700K',
            'core_count' => 12,
            'thread_count' => 20,
            'base_clock_ghz' => -1,
            'stock_quantity' => 25,
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('base_clock_ghz', $validator->errors()->toArray());
    }

    #[Test]
    public function it_accepts_decimal_clock_speeds(): void
    {
        $request = ProcessorSpecRequest::create('/test', 'POST');

        $data = [
            'manufacturer' => 'Intel',
            'model' => 'Core i7-12700K',
            'core_count' => 12,
            'thread_count' => 20,
            'base_clock_ghz' => 3.6,
            'boost_clock_ghz' => 5.0,
            'stock_quantity' => 25,
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertFalse($validator->fails());
    }

    #[Test]
    public function it_has_custom_attributes(): void
    {
        $request = new ProcessorSpecRequest();

        $attributes = $request->attributes();

        $this->assertEquals('number of cores', $attributes['core_count']);
        $this->assertEquals('number of threads', $attributes['thread_count']);
        $this->assertEquals('base clock speed', $attributes['base_clock_ghz']);
        $this->assertEquals('boost clock speed', $attributes['boost_clock_ghz']);
        $this->assertEquals('initial stock quantity', $attributes['stock_quantity']);
    }

    #[Test]
    public function it_has_custom_messages(): void
    {
        $request = new ProcessorSpecRequest();

        $messages = $request->messages();

        $this->assertStringContainsString('at least 1 core', $messages['core_count.min']);
        $this->assertStringContainsString('at least 1 thread', $messages['thread_count.min']);
        $this->assertStringContainsString('greater than 0', $messages['base_clock_ghz.min']);
    }
}
