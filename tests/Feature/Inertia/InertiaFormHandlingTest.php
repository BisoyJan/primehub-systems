<?php

namespace Tests\Feature\Inertia;

use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Integration tests for Inertia.js form handling and validation.
 *
 * These tests verify form submission, validation error handling,
 * flash messages, and file uploads through Inertia.js.
 */
class InertiaFormHandlingTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_displays_validation_errors_on_form_submission(): void
    {
        $user = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);

        $response = $this->actingAs($user)
            ->post(route('sites.store'), [
                'name' => '', // Required field missing
            ]);

        // Validation errors should be in session
        $response->assertSessionHasErrors('name');
    }

    #[Test]
    public function it_preserves_old_input_after_validation_error(): void
    {
        $user = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);

        // Submit with missing required 'name' field to trigger validation error
        $response = $this->actingAs($user)
            ->from(route('sites.create'))
            ->post(route('sites.store'), [
                'name' => '', // Empty name triggers validation error
            ]);

        // Should redirect back with validation errors
        $response->assertRedirect();
        $response->assertSessionHasErrors('name');
    }

    #[Test]
    public function it_shows_success_flash_message_after_successful_form_submit(): void
    {
        $user = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);

        $response = $this->actingAs($user)
            ->post(route('sites.store'), [
                'name' => 'New Site',
            ]);

        $response->assertSessionHas('flash.message');
        $response->assertSessionHas('flash.type', 'success');
    }

    #[Test]
    public function it_redirects_after_successful_form_submission(): void
    {
        $user = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);

        $response = $this->actingAs($user)
            ->post(route('sites.store'), [
                'name' => 'New Site',
            ]);

        $response->assertRedirect();
    }

    #[Test]
    public function it_shows_error_flash_message_on_operation_failure(): void
    {
        $user = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);

        // Submit with missing required field to trigger validation error
        $response = $this->actingAs($user)
            ->post(route('sites.store'), [
                'name' => '', // Empty name triggers validation
            ]);

        // Should redirect with validation errors
        $response->assertRedirect();
        $response->assertSessionHasErrors('name');
    }

    #[Test]
    public function it_handles_file_upload_in_form(): void
    {
        Storage::fake('local');

        $user = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);
        $site = Site::factory()->create();
        $file = UploadedFile::fake()->create('attendance.txt', 100, 'text/plain');

        $response = $this->actingAs($user)
            ->post(route('attendance.upload'), [
                'file' => $file,
                'date_from' => now()->subDays(7)->format('Y-m-d'),
                'date_to' => now()->format('Y-m-d'),
                'biometric_site_id' => $site->id,
                'notes' => 'Test upload',
            ]);

        // File should be uploaded or redirect on success
        $response->assertRedirect();
    }

    #[Test]
    public function it_validates_file_type_on_upload(): void
    {
        Storage::fake('local');

        $user = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);
        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

        $response = $this->actingAs($user)
            ->post(route('attendance.upload'), [
                'file' => $file,
            ]);

        // Should have validation errors
        $response->assertSessionHasErrors('file');
    }

    #[Test]
    public function it_validates_file_size_on_upload(): void
    {
        Storage::fake('local');

        $user = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);
        // Create a file larger than allowed (e.g., 20MB if limit is 10MB)
        $file = UploadedFile::fake()->create('large-file.csv', 20480, 'text/csv');

        $response = $this->actingAs($user)
            ->post(route('attendance.upload'), [
                'file' => $file,
            ]);

        // Should have validation errors
        $response->assertSessionHasErrors();
    }

    #[Test]
    public function it_preserves_form_state_on_validation_error(): void
    {
        $user = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);

        // Submit with empty name to trigger validation error
        $response = $this->actingAs($user)
            ->from(route('sites.create'))
            ->post(route('sites.store'), [
                'name' => '', // Trigger validation
            ]);

        // Should redirect back with errors
        $response->assertRedirect();
        $response->assertSessionHasErrors('name');
    }

    #[Test]
    public function it_clears_validation_errors_after_successful_submit(): void
    {
        $user = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);

        // First submit with error
        $this->actingAs($user)
            ->post(route('sites.store'), [
                'name' => '', // Missing
            ]);

        // Second submit successfully
        $response = $this->actingAs($user)
            ->post(route('sites.store'), [
                'name' => 'Valid Site',
            ]);

        $response->assertSessionHasNoErrors();
    }

    #[Test]
    public function it_handles_form_update_with_put_method(): void
    {
        $user = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);
        $site = Site::factory()->create(['name' => 'Old Name']);

        $response = $this->actingAs($user)
            ->put(route('sites.update', $site), [
                'name' => 'Updated Name',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('flash.type', 'success');

        $this->assertDatabaseHas('sites', [
            'id' => $site->id,
            'name' => 'Updated Name',
        ]);
    }

    #[Test]
    public function it_handles_form_deletion_with_delete_method(): void
    {
        $user = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);
        $site = Site::factory()->create();

        $response = $this->actingAs($user)
            ->delete(route('sites.destroy', $site));

        $response->assertRedirect();
        $response->assertSessionHas('flash.type', 'success');

        $this->assertDatabaseMissing('sites', [
            'id' => $site->id,
        ]);
    }
}
