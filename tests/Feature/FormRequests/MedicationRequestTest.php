<?php

namespace Tests\Feature\FormRequests;

use App\Models\MedicationRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests for MedicationRequest functionality.
 * Updated to match actual application behavior.
 */
class MedicationRequestTest extends TestCase
{
    use RefreshDatabase;

    protected User $employee;
    protected User $admin;
    protected array $medicationTypes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->employee = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
        ]);

        // Admin role has medication_requests permissions
        $this->admin = User::factory()->create([
            'role' => 'Admin',
            'is_approved' => true,
        ]);

        $this->medicationTypes = [
            'Declogen',
            'Biogesic',
            'Mefenamic Acid',
            'Kremil-S',
            'Cetirizine',
            'Saridon',
            'Diatabs',
        ];
    }

    #[Test]
    public function it_displays_medication_requests_index()
    {
        MedicationRequest::factory()->count(3)->create();

        $response = $this->actingAs($this->admin)
            ->get(route('medication-requests.index'));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('FormRequest/MedicationRequests/Index')
                ->has('medicationRequests.data', 3)
            );
    }

    #[Test]
    public function it_displays_create_medication_request_form()
    {
        $response = $this->actingAs($this->employee)
            ->get(route('medication-requests.create'));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('FormRequest/MedicationRequests/Create')
                ->has('medicationTypes', 7)
                ->has('onsetOptions', 3)
            );
    }

    #[Test]
    public function it_creates_medication_request_for_self()
    {
        $data = [
            'medication_type' => 'Biogesic',
            'reason' => 'Headache and fever symptoms',
            'onset_of_symptoms' => 'Just today',
            'agrees_to_policy' => true,
        ];

        $response = $this->actingAs($this->employee)
            ->post(route('medication-requests.store'), $data);

        $response->assertRedirect(route('medication-requests.index'));

        $this->assertDatabaseHas('medication_requests', [
            'user_id' => $this->employee->id,
            'medication_type' => 'Biogesic',
            'status' => 'pending',
            'agrees_to_policy' => true,
        ]);
    }

    #[Test]
    public function it_creates_medication_request_for_all_types()
    {
        foreach ($this->medicationTypes as $medicationType) {
            $data = [
                'medication_type' => $medicationType,
                'reason' => "Testing $medicationType request",
                'onset_of_symptoms' => 'Just today',
                'agrees_to_policy' => true,
            ];

            $response = $this->actingAs($this->employee)
                ->post(route('medication-requests.store'), $data);

            $response->assertRedirect(route('medication-requests.index'));
        }

        $this->assertEquals(7, MedicationRequest::count());
    }

    #[Test]
    public function admin_creates_request_for_another_employee()
    {
        $otherEmployee = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
        ]);

        $data = [
            'requested_for_user_id' => $otherEmployee->id,
            'medication_type' => 'Cetirizine',
            'reason' => 'Allergic reaction symptoms',
            'onset_of_symptoms' => 'More than 1 day',
            'agrees_to_policy' => true,
        ];

        $response = $this->actingAs($this->admin)
            ->post(route('medication-requests.store'), $data);

        $response->assertRedirect(route('medication-requests.index'));

        $this->assertDatabaseHas('medication_requests', [
            'user_id' => $otherEmployee->id,
            'medication_type' => 'Cetirizine',
        ]);
    }

    #[Test]
    public function it_validates_required_fields()
    {
        $response = $this->actingAs($this->employee)
            ->post(route('medication-requests.store'), []);

        $response->assertSessionHasErrors([
            'medication_type',
            'reason',
            'onset_of_symptoms',
            'agrees_to_policy',
        ]);
    }

    #[Test]
    public function it_validates_medication_type()
    {
        $data = [
            'medication_type' => 'Invalid Medicine',
            'reason' => 'Testing validation',
            'onset_of_symptoms' => 'Just today',
            'agrees_to_policy' => true,
        ];

        $response = $this->actingAs($this->employee)
            ->post(route('medication-requests.store'), $data);

        $response->assertSessionHasErrors('medication_type');
    }

    #[Test]
    public function it_validates_onset_of_symptoms()
    {
        $data = [
            'medication_type' => 'Biogesic',
            'reason' => 'Testing validation',
            'onset_of_symptoms' => 'Invalid onset',
            'agrees_to_policy' => true,
        ];

        $response = $this->actingAs($this->employee)
            ->post(route('medication-requests.store'), $data);

        $response->assertSessionHasErrors('onset_of_symptoms');
    }

    #[Test]
    public function it_requires_policy_agreement()
    {
        $data = [
            'medication_type' => 'Biogesic',
            'reason' => 'Testing policy requirement',
            'onset_of_symptoms' => 'Just today',
            'agrees_to_policy' => false,
        ];

        $response = $this->actingAs($this->employee)
            ->post(route('medication-requests.store'), $data);

        $response->assertSessionHasErrors('agrees_to_policy');
    }

    #[Test]
    public function admin_approves_medication_request()
    {
        $medicationRequest = MedicationRequest::factory()->create([
            'user_id' => $this->employee->id,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->admin)
            ->post(route('medication-requests.updateStatus', $medicationRequest), [
                'status' => 'approved',
                'admin_notes' => 'Approved for dispensing',
            ]);

        $response->assertRedirect();

        $medicationRequest->refresh();
        $this->assertEquals('approved', $medicationRequest->status);
        $this->assertEquals($this->admin->id, $medicationRequest->approved_by);
        $this->assertNotNull($medicationRequest->approved_at);
    }

    #[Test]
    public function admin_marks_medication_as_dispensed()
    {
        $medicationRequest = MedicationRequest::factory()->create([
            'user_id' => $this->employee->id,
            'status' => 'approved',
        ]);

        $response = $this->actingAs($this->admin)
            ->post(route('medication-requests.updateStatus', $medicationRequest), [
                'status' => 'dispensed',
                'admin_notes' => 'Medication given to employee',
            ]);

        $response->assertRedirect();

        $medicationRequest->refresh();
        $this->assertEquals('dispensed', $medicationRequest->status);
    }

    #[Test]
    public function admin_rejects_medication_request()
    {
        $medicationRequest = MedicationRequest::factory()->create([
            'user_id' => $this->employee->id,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->admin)
            ->post(route('medication-requests.updateStatus', $medicationRequest), [
                'status' => 'rejected',
                'admin_notes' => 'Medication not available',
            ]);

        $response->assertRedirect();

        $medicationRequest->refresh();
        $this->assertEquals('rejected', $medicationRequest->status);
    }

    #[Test]
    public function it_displays_medication_request_details()
    {
        $medicationRequest = MedicationRequest::factory()->create([
            'user_id' => $this->employee->id,
        ]);

        $response = $this->actingAs($this->employee)
            ->get(route('medication-requests.show', $medicationRequest));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('FormRequest/MedicationRequests/Show')
                ->where('medicationRequest.id', $medicationRequest->id)
            );
    }

    #[Test]
    public function employee_can_delete_own_pending_request()
    {
        $medicationRequest = MedicationRequest::factory()->create([
            'user_id' => $this->employee->id,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->employee)
            ->delete(route('medication-requests.destroy', $medicationRequest));

        $response->assertRedirect(route('medication-requests.index'));

        $this->assertDatabaseMissing('medication_requests', [
            'id' => $medicationRequest->id,
        ]);
    }

    #[Test]
    public function employee_cannot_delete_approved_request()
    {
        $medicationRequest = MedicationRequest::factory()->create([
            'user_id' => $this->employee->id,
            'status' => 'approved',
        ]);

        $response = $this->actingAs($this->employee)
            ->delete(route('medication-requests.destroy', $medicationRequest));

        $response->assertForbidden();

        $this->assertDatabaseHas('medication_requests', [
            'id' => $medicationRequest->id,
        ]);
    }

    #[Test]
    public function it_filters_requests_by_status()
    {
        MedicationRequest::factory()->create([
            'user_id' => $this->employee->id,
            'status' => 'approved',
        ]);

        MedicationRequest::factory()->create([
            'user_id' => $this->employee->id,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('medication-requests.index', ['status' => 'approved']));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('medicationRequests.data', 1)
                ->where('medicationRequests.data.0.status', 'approved')
            );
    }

    #[Test]
    public function it_filters_requests_by_search()
    {
        MedicationRequest::factory()->create([
            'user_id' => $this->employee->id,
            'medication_type' => 'Biogesic',
        ]);

        MedicationRequest::factory()->create([
            'user_id' => $this->employee->id,
            'medication_type' => 'Cetirizine',
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('medication-requests.index', ['search' => 'Biogesic']));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('medicationRequests.data', 1)
                ->where('medicationRequests.data.0.medication_type', 'Biogesic')
            );
    }

    #[Test]
    public function unauthorized_users_cannot_update_status()
    {
        $regularUser = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
        ]);

        $medicationRequest = MedicationRequest::factory()->create([
            'status' => 'pending',
        ]);

        $response = $this->actingAs($regularUser)
            ->post(route('medication-requests.updateStatus', $medicationRequest), [
                'status' => 'approved',
            ]);

        $response->assertForbidden();

        $medicationRequest->refresh();
        $this->assertEquals('pending', $medicationRequest->status);
    }

    #[Test]
    public function it_records_multiple_onset_options()
    {
        $onsetOptions = ['Just today', 'More than 1 day', 'More than 1 week'];

        foreach ($onsetOptions as $onset) {
            $data = [
                'medication_type' => 'Biogesic',
                'reason' => "Testing onset: $onset",
                'onset_of_symptoms' => $onset,
                'agrees_to_policy' => true,
            ];

            $response = $this->actingAs($this->employee)
                ->post(route('medication-requests.store'), $data);

            $response->assertRedirect();
        }

        $this->assertEquals(3, MedicationRequest::count());
    }
}
