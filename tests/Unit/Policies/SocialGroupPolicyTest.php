<?php

namespace Tests\Unit\Policies;

use App\Models\SocialGroup;
use App\Models\SocialGroupMember;
use App\Models\User;
use App\Policies\SocialGroupPolicy;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SocialGroupPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected SocialGroupPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = new SocialGroupPolicy(app(PermissionService::class));
    }

    #[Test]
    public function owner_with_manage_permission_can_manage_invites_and_members(): void
    {
        $owner = User::factory()->create(['role' => 'Team Lead', 'is_approved' => true, 'is_active' => true]);

        $group = SocialGroup::create([
            'name' => 'Policy Group',
            'description' => null,
            'visibility' => SocialGroup::VISIBILITY_PRIVATE,
            'campaign_id' => null,
            'created_by' => $owner->id,
            'is_archived' => false,
        ]);

        SocialGroupMember::create([
            'social_group_id' => $group->id,
            'user_id' => $owner->id,
            'role' => SocialGroupMember::ROLE_OWNER,
            'joined_at' => now(),
        ]);

        $this->assertTrue($this->policy->manageInvites($owner, $group));
        $this->assertTrue($this->policy->manageMembers($owner, $group));
    }

    #[Test]
    public function agent_cannot_manage_invites_or_members(): void
    {
        $owner = User::factory()->create(['role' => 'Admin', 'is_approved' => true, 'is_active' => true]);
        $agent = User::factory()->create(['role' => 'Agent', 'is_approved' => true, 'is_active' => true]);

        $group = SocialGroup::create([
            'name' => 'Guard Group',
            'description' => null,
            'visibility' => SocialGroup::VISIBILITY_PRIVATE,
            'campaign_id' => null,
            'created_by' => $owner->id,
            'is_archived' => false,
        ]);

        SocialGroupMember::create([
            'social_group_id' => $group->id,
            'user_id' => $owner->id,
            'role' => SocialGroupMember::ROLE_OWNER,
            'joined_at' => now(),
        ]);

        $this->assertFalse($this->policy->manageInvites($agent, $group));
        $this->assertFalse($this->policy->manageMembers($agent, $group));
    }

    #[Test]
    public function owner_can_transfer_ownership(): void
    {
        $owner = User::factory()->create(['role' => 'Team Lead', 'is_approved' => true, 'is_active' => true]);

        $group = SocialGroup::create([
            'name' => 'Ownership Group',
            'description' => null,
            'visibility' => SocialGroup::VISIBILITY_PRIVATE,
            'campaign_id' => null,
            'created_by' => $owner->id,
            'is_archived' => false,
        ]);

        SocialGroupMember::create([
            'social_group_id' => $group->id,
            'user_id' => $owner->id,
            'role' => SocialGroupMember::ROLE_OWNER,
            'joined_at' => now(),
        ]);

        $this->assertTrue($this->policy->transferOwnership($owner, $group));
    }

    #[Test]
    public function non_owner_team_lead_cannot_transfer_ownership(): void
    {
        $owner = User::factory()->create(['role' => 'Admin', 'is_approved' => true, 'is_active' => true]);
        $teamLead = User::factory()->create(['role' => 'Team Lead', 'is_approved' => true, 'is_active' => true]);

        $group = SocialGroup::create([
            'name' => 'Ownership Guard Group',
            'description' => null,
            'visibility' => SocialGroup::VISIBILITY_PRIVATE,
            'campaign_id' => null,
            'created_by' => $owner->id,
            'is_archived' => false,
        ]);

        SocialGroupMember::create([
            'social_group_id' => $group->id,
            'user_id' => $owner->id,
            'role' => SocialGroupMember::ROLE_OWNER,
            'joined_at' => now(),
        ]);

        SocialGroupMember::create([
            'social_group_id' => $group->id,
            'user_id' => $teamLead->id,
            'role' => SocialGroupMember::ROLE_ADMIN,
            'joined_at' => now(),
        ]);

        $this->assertFalse($this->policy->transferOwnership($teamLead, $group));
    }

    #[Test]
    public function moderator_can_transfer_ownership_even_without_membership(): void
    {
        $owner = User::factory()->create(['role' => 'Admin', 'is_approved' => true, 'is_active' => true]);
        $moderator = User::factory()->create(['role' => 'HR', 'is_approved' => true, 'is_active' => true]);

        $group = SocialGroup::create([
            'name' => 'Moderator Group',
            'description' => null,
            'visibility' => SocialGroup::VISIBILITY_PRIVATE,
            'campaign_id' => null,
            'created_by' => $owner->id,
            'is_archived' => false,
        ]);

        SocialGroupMember::create([
            'social_group_id' => $group->id,
            'user_id' => $owner->id,
            'role' => SocialGroupMember::ROLE_OWNER,
            'joined_at' => now(),
        ]);

        $this->assertTrue($this->policy->transferOwnership($moderator, $group));
    }
}
