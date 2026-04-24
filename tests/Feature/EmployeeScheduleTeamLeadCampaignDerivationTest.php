<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\EmployeeSchedule;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Verifies the "single Campaign dropdown hidden for Team Leads" UX change:
 * - For TLs, campaign_ids is the source of truth and is required.
 * - The schedule's primary campaign_id is auto-derived from campaign_ids[0].
 * - Agents are unaffected (single Campaign dropdown still drives campaign_id).
 */
class EmployeeScheduleTeamLeadCampaignDerivationTest extends TestCase
{
    use RefreshDatabase;

    private function createAdmin(): User
    {
        return User::factory()->create([
            'role' => 'Admin',
            'is_approved' => true,
        ]);
    }

    private function basePayload(): array
    {
        return [
            'shift_type' => 'morning_shift',
            'scheduled_time_in' => '05:00',
            'scheduled_time_out' => '14:00',
            'work_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
            'grace_period_minutes' => 15,
            'effective_date' => now()->toDateString(),
        ];
    }

    #[Test]
    public function tl_store_derives_campaign_id_from_first_managed_campaign(): void
    {
        $admin = $this->createAdmin();
        $tl = User::factory()->create(['role' => 'Team Lead', 'is_approved' => true]);
        $site = Site::factory()->create();
        $campaignA = Campaign::factory()->create();
        $campaignB = Campaign::factory()->create();
        $campaignC = Campaign::factory()->create();

        $response = $this->actingAs($admin)->post(route('employee-schedules.store'), array_merge(
            $this->basePayload(),
            [
                'user_id' => $tl->id,
                'site_id' => $site->id,
                // Note: NO campaign_id sent — UI hides that field for TLs.
                'campaign_ids' => [$campaignB->id, $campaignA->id, $campaignC->id],
            ]
        ));

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        // Schedule's campaign_id should be derived from campaign_ids[0] (campaignB).
        $this->assertDatabaseHas('employee_schedules', [
            'user_id' => $tl->id,
            'campaign_id' => $campaignB->id,
            'site_id' => $site->id,
        ]);

        // Pivot should contain ALL managed campaigns.
        $pivotIds = $tl->fresh()->campaigns()->pluck('campaigns.id')->sort()->values()->all();
        $expected = collect([$campaignA->id, $campaignB->id, $campaignC->id])->sort()->values()->all();
        $this->assertSame($expected, $pivotIds);
    }

    #[Test]
    public function tl_store_fails_validation_when_managed_campaigns_empty(): void
    {
        $admin = $this->createAdmin();
        $tl = User::factory()->create(['role' => 'Team Lead', 'is_approved' => true]);
        $site = Site::factory()->create();

        $response = $this->actingAs($admin)->post(route('employee-schedules.store'), array_merge(
            $this->basePayload(),
            [
                'user_id' => $tl->id,
                'site_id' => $site->id,
                // No campaign_ids — must fail because TLs require at least one.
            ]
        ));

        $response->assertSessionHasErrors('campaign_ids');
        $this->assertDatabaseMissing('employee_schedules', ['user_id' => $tl->id]);
    }

    #[Test]
    public function tl_update_resyncs_pivot_and_redrives_campaign_id(): void
    {
        $admin = $this->createAdmin();
        $tl = User::factory()->create(['role' => 'Team Lead', 'is_approved' => true]);
        $site = Site::factory()->create();
        $campaignA = Campaign::factory()->create();
        $campaignB = Campaign::factory()->create();
        $campaignC = Campaign::factory()->create();

        // Existing schedule + pivot starting state: A, B (primary = A).
        $tl->campaigns()->sync([$campaignA->id, $campaignB->id]);
        $schedule = EmployeeSchedule::factory()->create([
            'user_id' => $tl->id,
            'site_id' => $site->id,
            'campaign_id' => $campaignA->id,
            'is_active' => true,
        ]);

        // Update: reorder/replace managed campaigns to [C, B] — primary should become C.
        $response = $this->actingAs($admin)->put(
            route('employee-schedules.update', $schedule->id),
            array_merge($this->basePayload(), [
                'site_id' => $site->id,
                'campaign_ids' => [$campaignC->id, $campaignB->id],
                'is_active' => true,
            ])
        );

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $this->assertDatabaseHas('employee_schedules', [
            'id' => $schedule->id,
            'campaign_id' => $campaignC->id,
        ]);

        $pivotIds = $tl->fresh()->campaigns()->pluck('campaigns.id')->sort()->values()->all();
        $expected = collect([$campaignB->id, $campaignC->id])->sort()->values()->all();
        $this->assertSame($expected, $pivotIds, 'TL pivot should reflect the new managed campaigns exactly.');
    }

    #[Test]
    public function agent_store_still_uses_explicit_campaign_id(): void
    {
        $admin = $this->createAdmin();
        $agent = User::factory()->create(['role' => 'Agent', 'is_approved' => true]);
        $site = Site::factory()->create();
        $campaign = Campaign::factory()->create();

        $response = $this->actingAs($admin)->post(route('employee-schedules.store'), array_merge(
            $this->basePayload(),
            [
                'user_id' => $agent->id,
                'site_id' => $site->id,
                'campaign_id' => $campaign->id,
            ]
        ));

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $this->assertDatabaseHas('employee_schedules', [
            'user_id' => $agent->id,
            'campaign_id' => $campaign->id,
        ]);

        // Agents do not populate the campaign_user pivot.
        $this->assertSame(0, $agent->fresh()->campaigns()->count());
    }
}
