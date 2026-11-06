<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers;

use App\Jobs\RunDatabaseBackup;
use App\Models\DatabaseBackup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DatabaseBackupControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function createSuperAdmin(): User
    {
        return User::factory()->create([
            'role' => 'Super Admin',
            'is_approved' => true,
        ]);
    }

    private function createAgent(): User
    {
        return User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
        ]);
    }

    // ────────────────────────────────────────────────────────────────────────
    // Auth & Authorization
    // ────────────────────────────────────────────────────────────────────────

    #[Test]
    public function guests_are_redirected_to_login(): void
    {
        $this->get(route('database-backups.index'))
            ->assertRedirect(route('login'));
    }

    #[Test]
    public function unauthorized_users_cannot_access_backups(): void
    {
        $user = User::factory()->create([
            'role' => 'IT',
            'is_approved' => true,
        ]);

        $this->actingAs($user)
            ->get(route('database-backups.index'))
            ->assertForbidden();
    }

    // ────────────────────────────────────────────────────────────────────────
    // Index
    // ────────────────────────────────────────────────────────────────────────

    #[Test]
    public function super_admin_can_view_backups_index(): void
    {
        $admin = $this->createSuperAdmin();
        DatabaseBackup::factory()->count(3)->create(['created_by' => $admin->id]);

        $this->actingAs($admin)
            ->get(route('database-backups.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/DatabaseBackups/Index')
                ->has('backups.data', 3)
            );
    }

    #[Test]
    public function index_supports_search(): void
    {
        $admin = $this->createSuperAdmin();
        DatabaseBackup::factory()->create([
            'filename' => 'backup-2026-01-01.zip',
            'created_by' => $admin->id,
        ]);
        DatabaseBackup::factory()->create([
            'filename' => 'backup-2026-02-15.zip',
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->get(route('database-backups.index', ['search' => '2026-01-01']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/DatabaseBackups/Index')
                ->has('backups.data', 1)
            );
    }

    #[Test]
    public function index_imports_scheduled_backup_files_from_disk_as_system_backups(): void
    {
        $admin = $this->createSuperAdmin();

        $path = 'backups/2026-11-06-08-00-01.zip';
        Storage::disk('local')->put($path, 'zip-content');
        Storage::disk('local')->put('backups/notes.txt', 'not a backup');

        $producedAt = Carbon::parse('2026-11-06 08:00:01', config('app.timezone'));
        touch(Storage::disk('local')->path($path), $producedAt->getTimestamp());

        $this->actingAs($admin)
            ->get(route('database-backups.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/DatabaseBackups/Index')
                ->has('backups.data', 1)
                ->where('backups.data.0.filename', '2026-11-06-08-00-01.zip')
                ->where('backups.data.0.creator', null)
            );

        $backup = DatabaseBackup::where('path', $path)->sole();

        $this->assertNull($backup->created_by);
        $this->assertSame('completed', $backup->status);
        $this->assertSame(strlen('zip-content'), $backup->size);
        $this->assertSame('2026-11-06 08:00:01', $backup->created_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-06 08:00:01', $backup->completed_at->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function index_does_not_import_the_same_disk_backup_twice(): void
    {
        $admin = $this->createSuperAdmin();
        Storage::disk('local')->put('backups/2026-11-06-08-00-01.zip', 'zip-content');

        $this->actingAs($admin)->get(route('database-backups.index'))->assertOk();
        $this->actingAs($admin)->get(route('database-backups.index'))->assertOk();

        $this->assertSame(1, DatabaseBackup::where('path', 'backups/2026-11-06-08-00-01.zip')->count());
    }

    #[Test]
    public function index_skips_disk_backups_that_are_already_tracked(): void
    {
        $admin = $this->createSuperAdmin();
        Storage::disk('local')->put('backups/backup-2026-11-06-233316.zip', 'zip-content');

        DatabaseBackup::factory()->create([
            'filename' => 'backup-2026-11-06-233316.zip',
            'path' => 'backups/backup-2026-11-06-233316.zip',
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->get(route('database-backups.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('backups.data', 1)
                ->where('backups.data.0.creator.id', $admin->id)
            );
    }

    // ────────────────────────────────────────────────────────────────────────
    // Store
    // ────────────────────────────────────────────────────────────────────────

    #[Test]
    public function super_admin_can_create_backup(): void
    {
        Queue::fake();
        $admin = $this->createSuperAdmin();

        $this->actingAs($admin)
            ->post(route('database-backups.store'))
            ->assertRedirect(route('database-backups.index'));

        $this->assertDatabaseHas('database_backups', [
            'status' => 'pending',
            'created_by' => $admin->id,
        ]);

        Queue::assertPushed(RunDatabaseBackup::class);
    }

    #[Test]
    public function backup_creation_shares_the_job_id_so_the_page_can_poll_progress(): void
    {
        Queue::fake();
        $admin = $this->createSuperAdmin();

        $this->actingAs($admin)
            ->post(route('database-backups.store'))
            ->assertRedirect(route('database-backups.index'))
            ->assertSessionHas('backup_job_id');

        $jobId = session('backup_job_id');
        $this->assertIsString($jobId);

        $this->get(route('database-backups.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/DatabaseBackups/Index')
                ->where('flash.backup_job_id', $jobId)
                ->where('flash.type', 'success')
            );

        $this->get(route('database-backups.progress', ['jobId' => $jobId]))
            ->assertOk()
            ->assertJson([
                'percent' => 0,
                'status' => 'Queued, waiting for worker...',
                'finished' => false,
            ]);

        $this->get(route('database-backups.index'))
            ->assertInertia(fn ($page) => $page->where('flash.backup_job_id', null));
    }

    // ────────────────────────────────────────────────────────────────────────
    // Download
    // ────────────────────────────────────────────────────────────────────────

    #[Test]
    public function cannot_download_incomplete_backup(): void
    {
        $admin = $this->createSuperAdmin();
        $backup = DatabaseBackup::factory()->pending()->create(['created_by' => $admin->id]);

        $this->actingAs($admin)
            ->get(route('database-backups.download', $backup))
            ->assertRedirect(route('database-backups.index'));
    }

    // ────────────────────────────────────────────────────────────────────────
    // Destroy
    // ────────────────────────────────────────────────────────────────────────

    #[Test]
    public function super_admin_can_delete_backup(): void
    {
        $admin = $this->createSuperAdmin();
        $backup = DatabaseBackup::factory()->create(['created_by' => $admin->id]);

        $this->actingAs($admin)
            ->delete(route('database-backups.destroy', $backup))
            ->assertRedirect(route('database-backups.index'));

        $this->assertDatabaseMissing('database_backups', ['id' => $backup->id]);
    }

    // ────────────────────────────────────────────────────────────────────────
    // Clean Old
    // ────────────────────────────────────────────────────────────────────────

    #[Test]
    public function super_admin_can_clean_old_backups(): void
    {
        $admin = $this->createSuperAdmin();

        // Create old backup
        $old = DatabaseBackup::factory()->create([
            'created_by' => $admin->id,
            'created_at' => now()->subDays(60),
        ]);

        // Create recent backup
        $recent = DatabaseBackup::factory()->create([
            'created_by' => $admin->id,
            'created_at' => now()->subDays(5),
        ]);

        $this->actingAs($admin)
            ->post(route('database-backups.clean-old'), ['days' => 30])
            ->assertRedirect(route('database-backups.index'));

        $this->assertDatabaseMissing('database_backups', ['id' => $old->id]);
        $this->assertDatabaseHas('database_backups', ['id' => $recent->id]);
    }

    #[Test]
    public function clean_old_validates_days_parameter(): void
    {
        $admin = $this->createSuperAdmin();

        $this->actingAs($admin)
            ->post(route('database-backups.clean-old'), ['days' => 0])
            ->assertSessionHasErrors('days');

        $this->actingAs($admin)
            ->post(route('database-backups.clean-old'), ['days' => 400])
            ->assertSessionHasErrors('days');
    }

    // ────────────────────────────────────────────────────────────────────────
    // Progress
    // ────────────────────────────────────────────────────────────────────────

    #[Test]
    public function can_check_backup_progress(): void
    {
        $admin = $this->createSuperAdmin();

        $this->actingAs($admin)
            ->get(route('database-backups.progress', ['jobId' => 'test-job-id']))
            ->assertOk()
            ->assertJson([
                'percent' => 0,
                'status' => 'Waiting...',
                'finished' => false,
            ]);
    }
}
