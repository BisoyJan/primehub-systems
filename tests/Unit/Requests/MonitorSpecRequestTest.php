<?php

declare(strict_types=1);

namespace Tests\Unit\Requests;

use App\Http\Requests\MonitorSpecRequest;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MonitorSpecRequestTest extends TestCase
{
    #[Test]
    public function it_authorizes_all_users(): void
    {
        $request = new MonitorSpecRequest();

        $this->assertTrue($request->authorize());
    }

    #[Test]
    public function it_validates_with_complete_data(): void
    {
        $request = new MonitorSpecRequest();

        $data = [
            'brand' => 'Dell',
            'model' => 'P2422H',
            'screen_size' => 24,
            'resolution' => '1920x1080',
            'panel_type' => 'IPS',
            'ports' => ['HDMI', 'DisplayPort', 'VGA'],
            'notes' => 'Good for office use',
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertFalse($validator->fails());
    }

    #[Test]
    public function it_requires_all_mandatory_fields(): void
    {
        $request = new MonitorSpecRequest();
        $data = [];

        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $errors = $validator->errors()->toArray();
        $this->assertArrayHasKey('brand', $errors);
        $this->assertArrayHasKey('model', $errors);
        $this->assertArrayHasKey('screen_size', $errors);
        $this->assertArrayHasKey('resolution', $errors);
        $this->assertArrayHasKey('panel_type', $errors);
    }

    #[Test]
    public function it_allows_nullable_optional_fields(): void
    {
        $request = new MonitorSpecRequest();

        $data = [
            'brand' => 'Dell',
            'model' => 'P2422H',
            'screen_size' => 24,
            'resolution' => '1920x1080',
            'panel_type' => 'IPS',
            'ports' => null,
            'notes' => null,
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertFalse($validator->fails());
    }

    #[Test]
    public function it_requires_screen_size_to_be_at_least_10(): void
    {
        $request = new MonitorSpecRequest();

        $data = [
            'brand' => 'Dell',
            'model' => 'P2422H',
            'screen_size' => 9,
            'resolution' => '1920x1080',
            'panel_type' => 'IPS',
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('screen_size', $validator->errors()->toArray());
    }

    #[Test]
    public function it_requires_screen_size_to_be_at_most_100(): void
    {
        $request = new MonitorSpecRequest();

        $data = [
            'brand' => 'Dell',
            'model' => 'P2422H',
            'screen_size' => 101,
            'resolution' => '1920x1080',
            'panel_type' => 'IPS',
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('screen_size', $validator->errors()->toArray());
    }

    #[Test]
    public function it_only_accepts_valid_panel_types(): void
    {
        $request = new MonitorSpecRequest();

        $data = [
            'brand' => 'Dell',
            'model' => 'P2422H',
            'screen_size' => 24,
            'resolution' => '1920x1080',
            'panel_type' => 'LCD',
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('panel_type', $validator->errors()->toArray());
    }

    #[Test]
    public function it_accepts_all_valid_panel_types(): void
    {
        $request = new MonitorSpecRequest();
        $validTypes = ['IPS', 'VA', 'TN', 'OLED'];

        foreach ($validTypes as $type) {
            $data = [
                'brand' => 'Dell',
                'model' => 'P2422H',
                'screen_size' => 24,
                'resolution' => '1920x1080',
                'panel_type' => $type,
            ];

            $validator = Validator::make($data, $request->rules());

            $this->assertFalse($validator->fails(), "Panel type {$type} should be valid");
        }
    }

    #[Test]
    public function it_validates_ports_as_array(): void
    {
        $request = new MonitorSpecRequest();

        $data = [
            'brand' => 'Dell',
            'model' => 'P2422H',
            'screen_size' => 24,
            'resolution' => '1920x1080',
            'panel_type' => 'IPS',
            'ports' => 'HDMI',
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('ports', $validator->errors()->toArray());
    }

    #[Test]
    public function it_limits_notes_to_1000_characters(): void
    {
        $request = new MonitorSpecRequest();

        $data = [
            'brand' => 'Dell',
            'model' => 'P2422H',
            'screen_size' => 24,
            'resolution' => '1920x1080',
            'panel_type' => 'IPS',
            'notes' => str_repeat('a', 1001),
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('notes', $validator->errors()->toArray());
    }

    #[Test]
    public function it_has_custom_attributes(): void
    {
        $request = new MonitorSpecRequest();

        $attributes = $request->attributes();

        $this->assertEquals('screen size', $attributes['screen_size']);
        $this->assertEquals('panel type', $attributes['panel_type']);
        $this->assertEquals('port', $attributes['ports.*']);
    }

    #[Test]
    public function it_has_custom_messages(): void
    {
        $request = new MonitorSpecRequest();

        $messages = $request->messages();

        $this->assertStringContainsString('IPS, VA, TN, or OLED', $messages['panel_type.in']);
        $this->assertStringContainsString('at least 10 inches', $messages['screen_size.min']);
        $this->assertStringContainsString('cannot exceed 100 inches', $messages['screen_size.max']);
    }
}
