<?php

declare(strict_types=1);

namespace Tests\Unit\Requests;

use App\Http\Requests\SiteRequest;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SiteRequestTest extends TestCase
{
    private SiteRequest $request;

    protected function setUp(): void
    {
        parent::setUp();
        $this->request = new SiteRequest();
    }

    #[Test]
    public function it_authorizes_all_users(): void
    {
        $this->assertTrue($this->request->authorize());
    }

    #[Test]
    public function it_validates_with_valid_data(): void
    {
        $data = ['name' => 'Site A'];

        $validator = Validator::make($data, $this->request->rules());

        $this->assertFalse($validator->fails());
    }

    #[Test]
    public function it_requires_name(): void
    {
        $data = [];

        $validator = Validator::make($data, $this->request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('name', $validator->errors()->toArray());
    }

    #[Test]
    public function it_requires_name_to_be_string(): void
    {
        $data = ['name' => 12345];

        $validator = Validator::make($data, $this->request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('name', $validator->errors()->toArray());
    }

    #[Test]
    public function it_limits_name_to_255_characters(): void
    {
        $data = ['name' => str_repeat('a', 256)];

        $validator = Validator::make($data, $this->request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('name', $validator->errors()->toArray());
    }

    #[Test]
    public function it_accepts_name_at_max_length(): void
    {
        $data = ['name' => str_repeat('a', 255)];

        $validator = Validator::make($data, $this->request->rules());

        $this->assertFalse($validator->fails());
    }

    #[Test]
    public function it_accepts_short_names(): void
    {
        $data = ['name' => 'A'];

        $validator = Validator::make($data, $this->request->rules());

        $this->assertFalse($validator->fails());
    }
}
