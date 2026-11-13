<?php

namespace Tests\Feature\BreakTimer;

use App\Models\BreakRetentionPolicy;
use App\Models\BreakSession;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CheckBreakRetentionExpiryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_notifies_admins_about_sessions_expiring_within_the_warning_window(): void
    {
        $admin = User::factory()->create(['role' => 'Admin', 'is_approved' => true]);
        $agent = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);
        BreakRetentionPolicy::factory()->create(['retention_months' => 3]);

        BreakSession::factory()->lunch()->create([
            'shift_date' => now()->subMonths(3)->addDays(3)->toDateString(),
        ]);
        BreakSession::factory()->lunch()->create([
            'shift_date' => now()->subMonths(3)->addDays(20)->toDateString(),
        ]);

        $this->artisan('break-timer:check-expiry --days=7')
            ->expectsOutput('Break session expiry notifications sent to Admin and Super Admin.')
            ->assertSuccessful();

        $notification = Notification::query()->where('user_id', $admin->id)->sole();
        $this->assertSame('Break Session Data Expiring Soon', $notification->title);
        $this->assertSame(1, $notification->data['total_records']);
        $this->assertSame(['Lunch'], $notification->data['break_types_affected']);
        $this->assertStringContainsString('tab=retention', $notification->data['link']);

        $this->assertDatabaseMissing('notifications', ['user_id' => $agent->id]);
    }

    #[Test]
    public function it_sends_nothing_when_no_sessions_are_about_to_expire(): void
    {
        User::factory()->create(['role' => 'Admin', 'is_approved' => true]);
        BreakSession::factory()->create(['shift_date' => now()->subMonth()->toDateString()]);

        $this->artisan('break-timer:check-expiry --days=7')
            ->expectsOutput('No break sessions are expiring soon. No notifications sent.')
            ->assertSuccessful();

        $this->assertDatabaseCount('notifications', 0);
    }
}
