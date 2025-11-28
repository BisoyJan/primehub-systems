<?php

declare(strict_types=1);

namespace Tests\Unit\Requests;

use App\Http\Requests\ItConcernRequest;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ItConcernRequestTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_authorizes_all_users(): void
    {
        $request = new ItConcernRequest();

        $this->assertTrue($request->authorize());
    }

    #[Test]
    public function it_validates_with_complete_data(): void
    {
        $site = Site::factory()->create();

        $data = [
            'site_id' => $site->id,
            'station_number' => 'PC-001',
            'category' => 'Hardware',
            'priority' => 'high',
            'description' => 'Monitor is not displaying properly',
        ];

        $request = new ItConcernRequest();
        $validator = Validator::make($data, $request->rules());

        $this->assertFalse($validator->fails());
    }

    #[Test]
    public function it_requires_all_mandatory_fields_on_create(): void
    {
        $request = ItConcernRequest::create('/test', 'POST');
        $data = [];

        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $errors = $validator->errors()->toArray();
        $this->assertArrayHasKey('site_id', $errors);
        $this->assertArrayHasKey('station_number', $errors);
        $this->assertArrayHasKey('category', $errors);
        $this->assertArrayHasKey('priority', $errors);
        $this->assertArrayHasKey('description', $errors);
    }

    #[Test]
    public function it_only_accepts_valid_categories(): void
    {
        $site = Site::factory()->create();

        $data = [
            'site_id' => $site->id,
            'station_number' => 'PC-001',
            'category' => 'InvalidCategory',
            'priority' => 'high',
            'description' => 'Test description',
        ];

        $request = new ItConcernRequest();
        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('category', $validator->errors()->toArray());
    }

    #[Test]
    public function it_accepts_all_valid_categories(): void
    {
        $site = Site::factory()->create();
        $validCategories = ['Hardware', 'Software', 'Network/Connectivity', 'Other'];

        foreach ($validCategories as $category) {
            $data = [
                'site_id' => $site->id,
                'station_number' => 'PC-001',
                'category' => $category,
                'priority' => 'high',
                'description' => 'Test description',
            ];

            $request = new ItConcernRequest();
            $validator = Validator::make($data, $request->rules());

            $this->assertFalse($validator->fails(), "Category {$category} should be valid");
        }
    }

    #[Test]
    public function it_only_accepts_valid_priorities(): void
    {
        $site = Site::factory()->create();

        $data = [
            'site_id' => $site->id,
            'station_number' => 'PC-001',
            'category' => 'Hardware',
            'priority' => 'invalid',
            'description' => 'Test description',
        ];

        $request = new ItConcernRequest();
        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('priority', $validator->errors()->toArray());
    }

    #[Test]
    public function it_accepts_all_valid_priorities(): void
    {
        $site = Site::factory()->create();
        $validPriorities = ['low', 'medium', 'high', 'urgent'];

        foreach ($validPriorities as $priority) {
            $data = [
                'site_id' => $site->id,
                'station_number' => 'PC-001',
                'category' => 'Hardware',
                'priority' => $priority,
                'description' => 'Test description',
            ];

            $request = new ItConcernRequest();
            $validator = Validator::make($data, $request->rules());

            $this->assertFalse($validator->fails(), "Priority {$priority} should be valid");
        }
    }

    #[Test]
    public function it_limits_description_to_1000_characters(): void
    {
        $site = Site::factory()->create();

        $data = [
            'site_id' => $site->id,
            'station_number' => 'PC-001',
            'category' => 'Hardware',
            'priority' => 'high',
            'description' => str_repeat('a', 1001),
        ];

        $request = new ItConcernRequest();
        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('description', $validator->errors()->toArray());
    }

    #[Test]
    public function it_allows_nullable_user_id(): void
    {
        $site = Site::factory()->create();

        $data = [
            'user_id' => null,
            'site_id' => $site->id,
            'station_number' => 'PC-001',
            'category' => 'Hardware',
            'priority' => 'high',
            'description' => 'Test description',
        ];

        $request = new ItConcernRequest();
        $validator = Validator::make($data, $request->rules());

        $this->assertFalse($validator->fails());
    }

    #[Test]
    public function it_includes_status_validation_on_update(): void
    {
        $site = Site::factory()->create();

        $request = ItConcernRequest::create('/test', 'PUT');

        $data = [
            'site_id' => $site->id,
            'station_number' => 'PC-001',
            'category' => 'Hardware',
            'priority' => 'high',
            'description' => 'Test description',
            'status' => 'resolved',
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertFalse($validator->fails());
    }

    #[Test]
    public function it_validates_status_values_on_update(): void
    {
        $site = Site::factory()->create();
        $request = ItConcernRequest::create('/test', 'PATCH');

        $data = [
            'site_id' => $site->id,
            'station_number' => 'PC-001',
            'category' => 'Hardware',
            'priority' => 'high',
            'description' => 'Test description',
            'status' => 'invalid_status',
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('status', $validator->errors()->toArray());
    }

    #[Test]
    public function it_has_custom_attributes(): void
    {
        $request = new ItConcernRequest();

        $attributes = $request->attributes();

        $this->assertEquals('site', $attributes['site_id']);
        $this->assertEquals('station number', $attributes['station_number']);
        $this->assertEquals('category', $attributes['category']);
        $this->assertEquals('priority', $attributes['priority']);
        $this->assertEquals('description', $attributes['description']);
        $this->assertEquals('resolution notes', $attributes['resolution_notes']);
    }

    #[Test]
    public function it_has_custom_messages(): void
    {
        $request = new ItConcernRequest();

        $messages = $request->messages();

        $this->assertStringContainsString('select a site', $messages['site_id.required']);
        $this->assertStringContainsString('required', $messages['station_number.required']);
        $this->assertStringContainsString('select a category', $messages['category.required']);
        $this->assertStringContainsString('priority level', $messages['priority.required']);
        $this->assertStringContainsString('description', $messages['description.required']);
    }
}
