<?php

namespace Tests\Feature\Controllers\FormRequests;

use Tests\TestCase;
use App\Models\User;
use App\Models\MedicationRequest;
use App\Services\NotificationService;
use App\Mail\MedicationRequestSubmitted;
use App\Mail\MedicationRequestStatusUpdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Inertia\Testing\AssertableInertia as Assert;

class MedicationRequestControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Mock NotificationService
        $this->mock(NotificationService::class, function ($mock) {
            $mock->shouldReceive('notifyHrRolesAboutNewMedicationRequest')->andReturnNull();
            $mock->shouldReceive('notifyMedicationRequestStatusChange')->andReturn(\Mockery::mock(\App\Models\Notification::class));
            $mock->shouldReceive('notifyHrRolesAboutMedicationRequestCancellation')->andReturnNull();
        });
    }

    #[Test]
    public function it_displays_medication_requests_index()
    {
        $user = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);
        $request = MedicationRequest::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->get(route('medication-requests.index'));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('FormRequest/MedicationRequests/Index')
                ->has('medicationRequests.data', 1)
                ->where('medicationRequests.data.0.id', $request->id)
            );
    }

    #[Test]
    public function it_displays_create_form()
    {
        $user = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);

        $response = $this->actingAs($user)->get(route('medication-requests.create'));

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('FormRequest/MedicationRequests/Create')
                ->has('medicationTypes')
                ->has('onsetOptions')
            );
    }

    #[Test]
    public function it_stores_new_medication_request()
    {
        Mail::fake();
        $user = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);

        $data = [
            'medication_type' => 'Biogesic',
            'reason' => 'Headache',
            'onset_of_symptoms' => 'Just today',
            'agrees_to_policy' => true,
        ];

        $response = $this->actingAs($user)->post(route('medication-requests.store'), $data);

        $response->assertRedirect(route('medication-requests.index'));
        $this->assertDatabaseHas('medication_requests', [
            'user_id' => $user->id,
            'medication_type' => 'Biogesic',
            'status' => 'pending',
        ]);

        Mail::assertQueued(MedicationRequestSubmitted::class);
    }

    #[Test]
    public function it_allows_hr_to_update_status()
    {
        Mail::fake();
        $hrUser = User::factory()->create(['role' => 'HR', 'is_approved' => true]);
        $user = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);
        $request = MedicationRequest::factory()->create(['user_id' => $user->id, 'status' => 'pending']);

        $response = $this->actingAs($hrUser)->post(route('medication-requests.updateStatus', $request), [
            'status' => 'approved',
            'admin_notes' => 'Approved by HR',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('medication_requests', [
            'id' => $request->id,
            'status' => 'approved',
            'approved_by' => $hrUser->id,
            'admin_notes' => 'Approved by HR',
        ]);

        Mail::assertQueued(MedicationRequestStatusUpdated::class);
    }

    #[Test]
    public function it_allows_user_to_delete_pending_request()
    {
        $user = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);
        $request = MedicationRequest::factory()->create(['user_id' => $user->id, 'status' => 'pending']);

        $response = $this->actingAs($user)->delete(route('medication-requests.destroy', $request));

        $response->assertRedirect(route('medication-requests.index'));
        $this->assertDatabaseMissing('medication_requests', [
            'id' => $request->id,
        ]);
    }
}
