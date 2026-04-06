<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Campaign;
use App\Models\CoachingSession;
use App\Models\EmployeeSchedule;
use App\Models\ItConcern;
use App\Models\LeaveRequest;
use App\Models\MedicationRequest;
use App\Models\PcMaintenance;
use App\Models\PcSpec;
use App\Models\User;
use App\Services\LoginDigestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LoginDigestTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function dashboard_includes_login_digest_prop(): void
    {
        $admin = User::factory()->create([
            'role' => 'Admin',
            'is_approved' => true,
        ]);

        $response = $this->actingAs($admin)->get(route('dashboard'));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('loginDigest')
                ->has('loginDigest.greeting')
                ->has('loginDigest.items')
                ->has('loginDigest.total_actionable')
            );
    }

    #[Test]
    public function admin_digest_returns_correct_structure(): void
    {
        $admin = User::factory()->create([
            'role' => 'Admin',
            'is_approved' => true,
        ]);

        $service = app(LoginDigestService::class);
        $digest = $service->getDigest($admin);

        $this->assertArrayHasKey('greeting', $digest);
        $this->assertArrayHasKey('items', $digest);
        $this->assertArrayHasKey('total_actionable', $digest);
        $this->assertStringContains($admin->first_name, $digest['greeting']);
    }

    #[Test]
    public function admin_digest_counts_pending_leaves(): void
    {
        $admin = User::factory()->create([
            'role' => 'Admin',
            'is_approved' => true,
        ]);

        $agent = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
        ]);

        // Create pending leave requests
        LeaveRequest::factory()->count(3)->create([
            'user_id' => $agent->id,
            'status' => 'pending',
            'admin_approved_by' => null,
        ]);

        // Create an approved one (should NOT count)
        LeaveRequest::factory()->create([
            'user_id' => $agent->id,
            'status' => 'approved',
            'admin_approved_by' => $admin->id,
        ]);

        $service = app(LoginDigestService::class);
        $digest = $service->getDigest($admin);

        $pendingLeaveItem = collect($digest['items'])->firstWhere('key', 'pending_leaves');
        $this->assertNotNull($pendingLeaveItem);
        $this->assertEquals(3, $pendingLeaveItem['count']);
    }

    #[Test]
    public function hr_digest_returns_hr_specific_items(): void
    {
        $hr = User::factory()->create([
            'role' => 'HR',
            'is_approved' => true,
        ]);

        $service = app(LoginDigestService::class);
        $digest = $service->getDigest($hr);

        // HR should only have leave, coaching reviews, and medication keys
        $keys = collect($digest['items'])->pluck('key')->toArray();
        $validKeys = ['pending_leaves', 'coaching_reviews', 'pending_medication'];
        foreach ($keys as $key) {
            $this->assertContains($key, $validKeys, "HR digest has unexpected key: {$key}");
        }
    }

    #[Test]
    public function it_digest_returns_it_specific_items(): void
    {
        $it = User::factory()->create([
            'role' => 'IT',
            'is_approved' => true,
        ]);

        // Create some pending IT concerns
        ItConcern::factory()->count(2)->create([
            'status' => 'pending',
        ]);

        $service = app(LoginDigestService::class);
        $digest = $service->getDigest($it);

        $keys = collect($digest['items'])->pluck('key')->toArray();
        $this->assertContains('pending_it_concerns', $keys);

        $itConcernsItem = collect($digest['items'])->firstWhere('key', 'pending_it_concerns');
        $this->assertEquals(2, $itConcernsItem['count']);
    }

    #[Test]
    public function agent_digest_returns_personal_items(): void
    {
        $agent = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
        ]);

        // Create agent's pending leave
        LeaveRequest::factory()->create([
            'user_id' => $agent->id,
            'status' => 'pending',
        ]);

        // Create agent's IT concern
        ItConcern::factory()->create([
            'user_id' => $agent->id,
            'status' => 'pending',
        ]);

        $service = app(LoginDigestService::class);
        $digest = $service->getDigest($agent);

        $keys = collect($digest['items'])->pluck('key')->toArray();
        $this->assertContains('pending_leaves', $keys);
        $this->assertContains('open_it_concerns', $keys);

        // Should NOT contain admin-level items
        $this->assertNotContains('overdue_maintenance', $keys);
        $this->assertNotContains('pending_undertime', $keys);
    }

    #[Test]
    public function utility_digest_returns_minimal_items(): void
    {
        $utility = User::factory()->create([
            'role' => 'Utility',
            'is_approved' => true,
        ]);

        $service = app(LoginDigestService::class);
        $digest = $service->getDigest($utility);

        $keys = collect($digest['items'])->pluck('key')->toArray();
        $validKeys = ['pending_leaves', 'open_it_concerns'];
        foreach ($keys as $key) {
            $this->assertContains($key, $validKeys, "Utility digest has unexpected key: {$key}");
        }
    }

    #[Test]
    public function digest_filters_out_zero_count_items(): void
    {
        $admin = User::factory()->create([
            'role' => 'Admin',
            'is_approved' => true,
        ]);

        // No pending items at all
        $service = app(LoginDigestService::class);
        $digest = $service->getDigest($admin);

        foreach ($digest['items'] as $item) {
            $this->assertGreaterThan(0, $item['count'], "Item '{$item['key']}' should have been filtered out (count = 0)");
        }
    }

    #[Test]
    public function digest_items_are_sorted_by_priority(): void
    {
        $admin = User::factory()->create([
            'role' => 'Admin',
            'is_approved' => true,
        ]);

        $agent = User::factory()->create([
            'role' => 'Agent',
            'is_approved' => true,
        ]);

        // Create enough to trigger multiple priority levels
        LeaveRequest::factory()->count(6)->create([
            'user_id' => $agent->id,
            'status' => 'pending',
            'admin_approved_by' => null,
        ]);

        ItConcern::factory()->create(['status' => 'pending']);
        MedicationRequest::factory()->create(['status' => 'pending']);

        $service = app(LoginDigestService::class);
        $digest = $service->getDigest($admin);

        $priorityOrder = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
        $lastPriority = -1;
        foreach ($digest['items'] as $item) {
            $currentPriority = $priorityOrder[$item['priority']] ?? 4;
            $this->assertGreaterThanOrEqual($lastPriority, $currentPriority, "Items should be sorted by priority (critical → low)");
            $lastPriority = $currentPriority;
        }
    }

    #[Test]
    public function greeting_varies_by_user_name(): void
    {
        $user1 = User::factory()->create([
            'role' => 'Agent',
            'first_name' => 'Alice',
            'is_approved' => true,
        ]);
        $user2 = User::factory()->create([
            'role' => 'Agent',
            'first_name' => 'Bob',
            'is_approved' => true,
        ]);

        $service = app(LoginDigestService::class);

        $this->assertStringContains('Alice', $service->getDigest($user1)['greeting']);
        $this->assertStringContains('Bob', $service->getDigest($user2)['greeting']);
    }

    /**
     * Custom assertion for string containment.
     */
    private function assertStringContains(string $needle, string $haystack): void
    {
        $this->assertTrue(
            str_contains($haystack, $needle),
            "Failed asserting that '{$haystack}' contains '{$needle}'"
        );
    }
}
