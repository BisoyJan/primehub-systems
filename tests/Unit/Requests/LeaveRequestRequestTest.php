<?php

declare(strict_types=1);

namespace Tests\Unit\Requests;

use App\Http\Requests\LeaveRequestRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LeaveRequestRequestTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_authorizes_all_users(): void
    {
        $request = new LeaveRequestRequest();

        $this->assertTrue($request->authorize());
    }

    #[Test]
    public function it_validates_with_complete_data(): void
    {
        $data = [
            'leave_type' => 'VL',
            'start_date' => now()->addDays(5)->format('Y-m-d'),
            'end_date' => now()->addDays(7)->format('Y-m-d'),
            'reason' => 'Personal matters that need attention',
            'campaign_department' => 'Campaign A',
        ];

        $request = new LeaveRequestRequest();
        $validator = Validator::make($data, $request->rules());

        $this->assertFalse($validator->fails());
    }

    #[Test]
    public function it_requires_all_mandatory_fields(): void
    {
        $request = new LeaveRequestRequest();
        $data = [];

        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $errors = $validator->errors()->toArray();
        $this->assertArrayHasKey('leave_type', $errors);
        $this->assertArrayHasKey('start_date', $errors);
        $this->assertArrayHasKey('end_date', $errors);
        $this->assertArrayHasKey('reason', $errors);
        $this->assertArrayHasKey('campaign_department', $errors);
    }

    #[Test]
    public function it_only_accepts_valid_leave_types(): void
    {
        $data = [
            'leave_type' => 'INVALID',
            'start_date' => now()->addDays(5)->format('Y-m-d'),
            'end_date' => now()->addDays(7)->format('Y-m-d'),
            'reason' => 'Test reason for leave',
            'campaign_department' => 'Campaign A',
        ];

        $request = new LeaveRequestRequest();
        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('leave_type', $validator->errors()->toArray());
    }

    #[Test]
    public function it_accepts_all_valid_leave_types(): void
    {
        $validTypes = ['VL', 'SL', 'BL', 'SPL', 'LOA', 'LDV', 'UPTO'];

        foreach ($validTypes as $type) {
            $data = [
                'leave_type' => $type,
                'start_date' => now()->addDays(5)->format('Y-m-d'),
                'end_date' => now()->addDays(7)->format('Y-m-d'),
                'reason' => 'Test reason for leave',
                'campaign_department' => 'Campaign A',
            ];

            $request = new LeaveRequestRequest();
            $validator = Validator::make($data, $request->rules());

            $this->assertFalse($validator->fails(), "Leave type {$type} should be valid");
        }
    }

    #[Test]
    public function it_requires_start_date_not_in_past(): void
    {
        $data = [
            'leave_type' => 'VL',
            'start_date' => now()->subDays(5)->format('Y-m-d'),
            'end_date' => now()->addDays(7)->format('Y-m-d'),
            'reason' => 'Test reason for leave',
            'campaign_department' => 'Campaign A',
        ];

        $request = new LeaveRequestRequest();
        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('start_date', $validator->errors()->toArray());
    }

    #[Test]
    public function it_requires_end_date_after_or_equal_start_date(): void
    {
        $data = [
            'leave_type' => 'VL',
            'start_date' => now()->addDays(7)->format('Y-m-d'),
            'end_date' => now()->addDays(5)->format('Y-m-d'),
            'reason' => 'Test reason for leave',
            'campaign_department' => 'Campaign A',
        ];

        $request = new LeaveRequestRequest();
        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('end_date', $validator->errors()->toArray());
    }

    #[Test]
    public function it_requires_reason_to_be_at_least_10_characters(): void
    {
        $data = [
            'leave_type' => 'VL',
            'start_date' => now()->addDays(5)->format('Y-m-d'),
            'end_date' => now()->addDays(7)->format('Y-m-d'),
            'reason' => 'Short',
            'campaign_department' => 'Campaign A',
        ];

        $request = new LeaveRequestRequest();
        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('reason', $validator->errors()->toArray());
    }

    #[Test]
    public function it_limits_reason_to_1000_characters(): void
    {
        $data = [
            'leave_type' => 'VL',
            'start_date' => now()->addDays(5)->format('Y-m-d'),
            'end_date' => now()->addDays(7)->format('Y-m-d'),
            'reason' => str_repeat('a', 1001),
            'campaign_department' => 'Campaign A',
        ];

        $request = new LeaveRequestRequest();
        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('reason', $validator->errors()->toArray());
    }

    #[Test]
    public function it_allows_admins_to_specify_employee_id(): void
    {
        $admin = User::factory()->create(['role' => 'Admin']);
        $employee = User::factory()->create();

        $request = new LeaveRequestRequest();
        $request->setUserResolver(fn() => $admin);

        $data = [
            'leave_type' => 'VL',
            'start_date' => now()->addDays(5)->format('Y-m-d'),
            'end_date' => now()->addDays(7)->format('Y-m-d'),
            'reason' => 'Test reason for leave',
            'campaign_department' => 'Campaign A',
            'employee_id' => $employee->id,
        ];

        $validator = Validator::make($data, $request->rules());

        $this->assertFalse($validator->fails());
    }

    #[Test]
    public function it_does_not_allow_employee_id_for_non_admins(): void
    {
        $agent = User::factory()->create(['role' => 'Agent']);
        $employee = User::factory()->create();

        $request = new LeaveRequestRequest();
        $request->setUserResolver(fn() => $agent);

        $data = [
            'leave_type' => 'VL',
            'start_date' => now()->addDays(5)->format('Y-m-d'),
            'end_date' => now()->addDays(7)->format('Y-m-d'),
            'reason' => 'Test reason for leave',
            'campaign_department' => 'Campaign A',
            'employee_id' => $employee->id,
        ];

        $rules = $request->rules();

        $this->assertArrayNotHasKey('employee_id', $rules);
    }

    #[Test]
    public function it_has_custom_messages(): void
    {
        $request = new LeaveRequestRequest();

        $messages = $request->messages();

        $this->assertStringContainsString('select a leave type', $messages['leave_type.required']);
        $this->assertStringContainsString('cannot be in the past', $messages['start_date.after_or_equal']);
        $this->assertStringContainsString('on or after the start date', $messages['end_date.after_or_equal']);
        $this->assertStringContainsString('at least 10 characters', $messages['reason.min']);
        $this->assertStringContainsString('cannot exceed 1000 characters', $messages['reason.max']);
    }
}
