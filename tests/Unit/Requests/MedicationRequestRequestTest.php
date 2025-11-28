<?php

declare(strict_types=1);

namespace Tests\Unit\Requests;

use App\Http\Requests\MedicationRequestRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MedicationRequestRequestTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_authorizes_all_users(): void
    {
        $request = new MedicationRequestRequest();

        $this->assertTrue($request->authorize());
    }

    #[Test]
    public function it_validates_with_complete_data(): void
    {
        $data = [
            'medication_type' => 'Biogesic',
            'reason' => 'Experiencing severe headache',
            'onset_of_symptoms' => 'Just today',
            'agrees_to_policy' => true,
        ];

        $request = new MedicationRequestRequest();
        $validator = Validator::make($data, $request->rules());

        $this->assertFalse($validator->fails());
    }

    #[Test]
    public function it_requires_all_mandatory_fields(): void
    {
        $request = new MedicationRequestRequest();
        $data = [];

        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $errors = $validator->errors()->toArray();
        $this->assertArrayHasKey('medication_type', $errors);
        $this->assertArrayHasKey('reason', $errors);
        $this->assertArrayHasKey('onset_of_symptoms', $errors);
        $this->assertArrayHasKey('agrees_to_policy', $errors);
    }

    #[Test]
    public function it_only_accepts_valid_medication_types(): void
    {
        $data = [
            'medication_type' => 'Paracetamol',
            'reason' => 'Experiencing severe headache',
            'onset_of_symptoms' => 'Just today',
            'agrees_to_policy' => true,
        ];

        $request = new MedicationRequestRequest();
        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('medication_type', $validator->errors()->toArray());
    }

    #[Test]
    public function it_accepts_all_valid_medication_types(): void
    {
        $validTypes = ['Declogen', 'Biogesic', 'Mefenamic Acid', 'Kremil-S', 'Cetirizine', 'Saridon', 'Diatabs'];

        foreach ($validTypes as $type) {
            $data = [
                'medication_type' => $type,
                'reason' => 'Experiencing symptoms',
                'onset_of_symptoms' => 'Just today',
                'agrees_to_policy' => true,
            ];

            $request = new MedicationRequestRequest();
            $validator = Validator::make($data, $request->rules());

            $this->assertFalse($validator->fails(), "Medication type {$type} should be valid");
        }
    }

    #[Test]
    public function it_only_accepts_valid_onset_of_symptoms(): void
    {
        $data = [
            'medication_type' => 'Biogesic',
            'reason' => 'Experiencing severe headache',
            'onset_of_symptoms' => 'Invalid onset',
            'agrees_to_policy' => true,
        ];

        $request = new MedicationRequestRequest();
        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('onset_of_symptoms', $validator->errors()->toArray());
    }

    #[Test]
    public function it_accepts_all_valid_onset_values(): void
    {
        $validOnsets = ['Just today', 'More than 1 day', 'More than 1 week'];

        foreach ($validOnsets as $onset) {
            $data = [
                'medication_type' => 'Biogesic',
                'reason' => 'Experiencing symptoms',
                'onset_of_symptoms' => $onset,
                'agrees_to_policy' => true,
            ];

            $request = new MedicationRequestRequest();
            $validator = Validator::make($data, $request->rules());

            $this->assertFalse($validator->fails(), "Onset value {$onset} should be valid");
        }
    }

    #[Test]
    public function it_requires_policy_agreement_to_be_accepted(): void
    {
        $data = [
            'medication_type' => 'Biogesic',
            'reason' => 'Experiencing severe headache',
            'onset_of_symptoms' => 'Just today',
            'agrees_to_policy' => false,
        ];

        $request = new MedicationRequestRequest();
        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('agrees_to_policy', $validator->errors()->toArray());
    }

    #[Test]
    public function it_limits_reason_to_1000_characters(): void
    {
        $data = [
            'medication_type' => 'Biogesic',
            'reason' => str_repeat('a', 1001),
            'onset_of_symptoms' => 'Just today',
            'agrees_to_policy' => true,
        ];

        $request = new MedicationRequestRequest();
        $validator = Validator::make($data, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('reason', $validator->errors()->toArray());
    }

    #[Test]
    public function it_allows_nullable_requested_for_user_id(): void
    {
        $data = [
            'requested_for_user_id' => null,
            'medication_type' => 'Biogesic',
            'reason' => 'Experiencing severe headache',
            'onset_of_symptoms' => 'Just today',
            'agrees_to_policy' => true,
        ];

        $request = new MedicationRequestRequest();
        $validator = Validator::make($data, $request->rules());

        $this->assertFalse($validator->fails());
    }

    #[Test]
    public function it_has_custom_attributes(): void
    {
        $request = new MedicationRequestRequest();

        $attributes = $request->attributes();

        $this->assertEquals('type of medication', $attributes['medication_type']);
        $this->assertEquals('onset of symptoms', $attributes['onset_of_symptoms']);
        $this->assertEquals('policy agreement', $attributes['agrees_to_policy']);
    }
}
