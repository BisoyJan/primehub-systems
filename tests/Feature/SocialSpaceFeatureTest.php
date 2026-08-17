<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\EmployeeSchedule;
use App\Models\Site;
use App\Models\SocialDirectMessage;
use App\Models\SocialDirectThread;
use App\Models\SocialDirectThreadParticipant;
use App\Models\SocialGroup;
use App\Models\SocialGroupInvite;
use App\Models\SocialGroupMember;
use App\Models\SocialMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SocialSpaceFeatureTest extends TestCase
{
    use RefreshDatabase;

    private function createApprovedUser(string $role = 'Agent'): User
    {
        return User::factory()->create([
            'role' => $role,
            'is_approved' => true,
            'is_active' => true,
        ]);
    }

    private function createApprovedAgentWithCampaign(Campaign $campaign): User
    {
        $agent = $this->createApprovedUser('Agent');

        EmployeeSchedule::factory()->create([
            'user_id' => $agent->id,
            'site_id' => Site::factory()->create()->id,
            'campaign_id' => $campaign->id,
            'is_active' => true,
        ]);

        return $agent;
    }

    #[Test]
    public function it_creates_group_and_adds_creator_as_owner(): void
    {
        $user = $this->createApprovedUser('Admin');

        $response = $this->actingAs($user)->post(route('social-space.groups.store'), [
            'name' => 'Quality Squad',
            'description' => 'Daily QA and issue triage.',
            'visibility' => 'public',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $group = SocialGroup::query()->where('name', 'Quality Squad')->first();

        $this->assertNotNull($group);

        $this->assertDatabaseHas('social_group_members', [
            'social_group_id' => $group->id,
            'user_id' => $user->id,
            'role' => SocialGroupMember::ROLE_OWNER,
        ]);
    }

    #[Test]
    public function it_allows_member_to_post_message_in_public_group(): void
    {
        $creator = $this->createApprovedUser('Admin');
        $member = $this->createApprovedUser('Admin');

        $group = SocialGroup::create([
            'name' => 'General',
            'description' => null,
            'visibility' => SocialGroup::VISIBILITY_PUBLIC,
            'campaign_id' => null,
            'created_by' => $creator->id,
            'is_archived' => false,
        ]);

        SocialGroupMember::create([
            'social_group_id' => $group->id,
            'user_id' => $creator->id,
            'role' => SocialGroupMember::ROLE_OWNER,
            'joined_at' => now(),
        ]);

        $response = $this->actingAs($member)->post(route('social-space.messages.store', $group), [
            'message' => 'Hello team!',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $this->assertDatabaseHas('social_messages', [
            'social_group_id' => $group->id,
            'user_id' => $member->id,
            'message' => 'Hello team!',
        ]);
    }

    #[Test]
    public function it_blocks_non_member_from_private_group_messaging(): void
    {
        $creator = $this->createApprovedUser('Admin');
        $outsider = $this->createApprovedUser('Admin');

        $group = SocialGroup::create([
            'name' => 'Leadership',
            'description' => null,
            'visibility' => SocialGroup::VISIBILITY_PRIVATE,
            'campaign_id' => null,
            'created_by' => $creator->id,
            'is_archived' => false,
        ]);

        SocialGroupMember::create([
            'social_group_id' => $group->id,
            'user_id' => $creator->id,
            'role' => SocialGroupMember::ROLE_OWNER,
            'joined_at' => now(),
        ]);

        $response = $this->actingAs($outsider)->post(route('social-space.messages.store', $group), [
            'message' => 'Can I join?',
        ]);

        $response->assertForbidden();

        $this->assertSame(0, SocialMessage::query()->count());
    }

    #[Test]
    public function it_limits_campaign_group_creation_to_user_campaign(): void
    {
        $campaign = Campaign::factory()->create();
        $otherCampaign = Campaign::factory()->create();

        $agent = $this->createApprovedAgentWithCampaign($campaign);

        $validResponse = $this->actingAs($agent)->post(route('social-space.groups.store'), [
            'name' => 'Campaign A Group',
            'visibility' => SocialGroup::VISIBILITY_CAMPAIGN,
            'campaign_id' => $campaign->id,
        ]);

        $validResponse->assertRedirect();
        $validResponse->assertSessionHasNoErrors();

        $invalidResponse = $this->actingAs($agent)->post(route('social-space.groups.store'), [
            'name' => 'Campaign B Group',
            'visibility' => SocialGroup::VISIBILITY_CAMPAIGN,
            'campaign_id' => $otherCampaign->id,
        ]);

        $invalidResponse->assertSessionHasErrors('campaign_id');

        $this->assertDatabaseMissing('social_groups', [
            'name' => 'Campaign B Group',
            'campaign_id' => $otherCampaign->id,
        ]);
    }

    #[Test]
    public function owner_can_invite_user_to_private_group(): void
    {
        $owner = $this->createApprovedUser('Admin');
        $invitee = $this->createApprovedUser('Admin');

        $group = SocialGroup::create([
            'name' => 'Private Ops',
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

        $response = $this->actingAs($owner)->post(route('social-space.invites.store', $group), [
            'invited_user_id' => $invitee->id,
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $this->assertDatabaseHas('social_group_invites', [
            'social_group_id' => $group->id,
            'invited_by' => $owner->id,
            'invited_user_id' => $invitee->id,
            'status' => SocialGroupInvite::STATUS_PENDING,
        ]);
    }

    #[Test]
    public function invited_user_can_accept_invite_and_become_group_member(): void
    {
        $owner = $this->createApprovedUser('Admin');
        $invitee = $this->createApprovedUser('Admin');

        $group = SocialGroup::create([
            'name' => 'Leadership Core',
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

        $invite = SocialGroupInvite::create([
            'social_group_id' => $group->id,
            'invited_by' => $owner->id,
            'invited_user_id' => $invitee->id,
            'status' => SocialGroupInvite::STATUS_PENDING,
        ]);

        $response = $this->actingAs($invitee)->post(route('social-space.invites.accept', $invite));

        $response->assertRedirect();

        $this->assertDatabaseHas('social_group_invites', [
            'id' => $invite->id,
            'status' => SocialGroupInvite::STATUS_ACCEPTED,
        ]);

        $this->assertDatabaseHas('social_group_members', [
            'social_group_id' => $group->id,
            'user_id' => $invitee->id,
            'role' => SocialGroupMember::ROLE_MEMBER,
        ]);
    }

    #[Test]
    public function invited_user_can_decline_invite(): void
    {
        $owner = $this->createApprovedUser('Admin');
        $invitee = $this->createApprovedUser('Admin');

        $group = SocialGroup::create([
            'name' => 'One-off Group',
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

        $invite = SocialGroupInvite::create([
            'social_group_id' => $group->id,
            'invited_by' => $owner->id,
            'invited_user_id' => $invitee->id,
            'status' => SocialGroupInvite::STATUS_PENDING,
        ]);

        $response = $this->actingAs($invitee)->post(route('social-space.invites.decline', $invite));

        $response->assertRedirect();

        $this->assertDatabaseHas('social_group_invites', [
            'id' => $invite->id,
            'status' => SocialGroupInvite::STATUS_DECLINED,
        ]);

        $this->assertDatabaseMissing('social_group_members', [
            'social_group_id' => $group->id,
            'user_id' => $invitee->id,
        ]);
    }

    #[Test]
    public function user_cannot_accept_someone_elses_invite(): void
    {
        $owner = $this->createApprovedUser('Admin');
        $invitee = $this->createApprovedUser('Admin');
        $otherUser = $this->createApprovedUser('Admin');

        $group = SocialGroup::create([
            'name' => 'Restricted Group',
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

        $invite = SocialGroupInvite::create([
            'social_group_id' => $group->id,
            'invited_by' => $owner->id,
            'invited_user_id' => $invitee->id,
            'status' => SocialGroupInvite::STATUS_PENDING,
        ]);

        $response = $this->actingAs($otherUser)->post(route('social-space.invites.accept', $invite));

        $response->assertForbidden();

        $this->assertDatabaseHas('social_group_invites', [
            'id' => $invite->id,
            'status' => SocialGroupInvite::STATUS_PENDING,
        ]);
    }

    #[Test]
    public function owner_can_revoke_pending_invite(): void
    {
        $owner = $this->createApprovedUser('Admin');
        $invitee = $this->createApprovedUser('Admin');

        $group = SocialGroup::create([
            'name' => 'Ops Revoke',
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

        $invite = SocialGroupInvite::create([
            'social_group_id' => $group->id,
            'invited_by' => $owner->id,
            'invited_user_id' => $invitee->id,
            'status' => SocialGroupInvite::STATUS_PENDING,
        ]);

        $response = $this->actingAs($owner)->post(route('social-space.invites.revoke', [$group, $invite]));

        $response->assertRedirect();

        $this->assertDatabaseHas('social_group_invites', [
            'id' => $invite->id,
            'status' => SocialGroupInvite::STATUS_REVOKED,
        ]);

        $this->assertDatabaseHas('activity_log', [
            'description' => 'social_space.invite_revoked',
            'causer_id' => $owner->id,
        ]);
    }

    #[Test]
    public function owner_can_remove_non_owner_member(): void
    {
        $owner = $this->createApprovedUser('Admin');
        $memberUser = $this->createApprovedUser('Admin');

        $group = SocialGroup::create([
            'name' => 'Ops Remove',
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

        $memberRecord = SocialGroupMember::create([
            'social_group_id' => $group->id,
            'user_id' => $memberUser->id,
            'role' => SocialGroupMember::ROLE_MEMBER,
            'joined_at' => now(),
        ]);

        $response = $this->actingAs($owner)->delete(route('social-space.members.remove', [$group, $memberRecord]));

        $response->assertRedirect();

        $this->assertDatabaseMissing('social_group_members', [
            'id' => $memberRecord->id,
        ]);

        $this->assertDatabaseHas('activity_log', [
            'description' => 'social_space.member_removed',
            'causer_id' => $owner->id,
        ]);
    }

    #[Test]
    public function owner_can_promote_and_demote_member_roles(): void
    {
        $owner = $this->createApprovedUser('Admin');
        $memberUser = $this->createApprovedUser('Admin');

        $group = SocialGroup::create([
            'name' => 'Ops Roles',
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

        $memberRecord = SocialGroupMember::create([
            'social_group_id' => $group->id,
            'user_id' => $memberUser->id,
            'role' => SocialGroupMember::ROLE_MEMBER,
            'joined_at' => now(),
        ]);

        $promoteResponse = $this->actingAs($owner)->patch(route('social-space.members.role', [$group, $memberRecord]), [
            'role' => SocialGroupMember::ROLE_ADMIN,
        ]);

        $promoteResponse->assertRedirect();

        $this->assertDatabaseHas('social_group_members', [
            'id' => $memberRecord->id,
            'role' => SocialGroupMember::ROLE_ADMIN,
        ]);

        $demoteResponse = $this->actingAs($owner)->patch(route('social-space.members.role', [$group, $memberRecord]), [
            'role' => SocialGroupMember::ROLE_MEMBER,
        ]);

        $demoteResponse->assertRedirect();

        $this->assertDatabaseHas('social_group_members', [
            'id' => $memberRecord->id,
            'role' => SocialGroupMember::ROLE_MEMBER,
        ]);
    }

    #[Test]
    public function owner_can_transfer_group_ownership(): void
    {
        $owner = $this->createApprovedUser('Admin');
        $adminUser = $this->createApprovedUser('Admin');

        $group = SocialGroup::create([
            'name' => 'Ops Transfer',
            'description' => null,
            'visibility' => SocialGroup::VISIBILITY_PRIVATE,
            'campaign_id' => null,
            'created_by' => $owner->id,
            'is_archived' => false,
        ]);

        $ownerRecord = SocialGroupMember::create([
            'social_group_id' => $group->id,
            'user_id' => $owner->id,
            'role' => SocialGroupMember::ROLE_OWNER,
            'joined_at' => now(),
        ]);

        $adminRecord = SocialGroupMember::create([
            'social_group_id' => $group->id,
            'user_id' => $adminUser->id,
            'role' => SocialGroupMember::ROLE_ADMIN,
            'joined_at' => now(),
        ]);

        $response = $this->actingAs($owner)->post(route('social-space.members.transfer-ownership', [$group, $adminRecord]));

        $response->assertRedirect();

        $this->assertDatabaseHas('social_group_members', [
            'id' => $adminRecord->id,
            'role' => SocialGroupMember::ROLE_OWNER,
        ]);

        $this->assertDatabaseHas('social_group_members', [
            'id' => $ownerRecord->id,
            'role' => SocialGroupMember::ROLE_ADMIN,
        ]);

        $this->assertDatabaseHas('activity_log', [
            'description' => 'social_space.ownership_transferred',
            'causer_id' => $owner->id,
        ]);
    }

    #[Test]
    public function admin_cannot_transfer_group_ownership(): void
    {
        $owner = $this->createApprovedUser('Admin');
        $adminUser = $this->createApprovedUser('Team Lead');
        $memberUser = $this->createApprovedUser('Admin');

        $group = SocialGroup::create([
            'name' => 'Ops Transfer Guard',
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

        $adminRecord = SocialGroupMember::create([
            'social_group_id' => $group->id,
            'user_id' => $adminUser->id,
            'role' => SocialGroupMember::ROLE_ADMIN,
            'joined_at' => now(),
        ]);

        $memberRecord = SocialGroupMember::create([
            'social_group_id' => $group->id,
            'user_id' => $memberUser->id,
            'role' => SocialGroupMember::ROLE_MEMBER,
            'joined_at' => now(),
        ]);

        $response = $this->actingAs($adminUser)->post(route('social-space.members.transfer-ownership', [$group, $memberRecord]));

        $response->assertRedirect();

        $this->assertDatabaseHas('social_group_members', [
            'id' => $adminRecord->id,
            'role' => SocialGroupMember::ROLE_ADMIN,
        ]);

        $this->assertDatabaseHas('social_group_members', [
            'id' => $memberRecord->id,
            'role' => SocialGroupMember::ROLE_MEMBER,
        ]);
    }

    #[Test]
    public function agent_without_manage_group_permission_cannot_manage_invites(): void
    {
        $owner = $this->createApprovedUser('Admin');
        $agent = $this->createApprovedUser('Agent');
        $invitee = $this->createApprovedUser('Admin');

        $group = SocialGroup::create([
            'name' => 'Ops Manage Guard',
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

        $response = $this->actingAs($agent)->post(route('social-space.invites.store', $group), [
            'invited_user_id' => $invitee->id,
        ]);

        $response->assertRedirect();

        $this->assertDatabaseMissing('social_group_invites', [
            'social_group_id' => $group->id,
            'invited_by' => $agent->id,
            'invited_user_id' => $invitee->id,
        ]);
    }

    #[Test]
    public function group_admin_can_manage_invites_and_members_but_cannot_transfer_ownership(): void
    {
        $owner = $this->createApprovedUser('Admin');
        $groupAdmin = $this->createApprovedUser('IT');
        $memberUser = $this->createApprovedUser('Admin');
        $invitee = $this->createApprovedUser('Admin');

        $group = SocialGroup::create([
            'name' => 'Ops Admin Capabilities',
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
            'user_id' => $groupAdmin->id,
            'role' => SocialGroupMember::ROLE_ADMIN,
            'joined_at' => now(),
        ]);

        $memberRecord = SocialGroupMember::create([
            'social_group_id' => $group->id,
            'user_id' => $memberUser->id,
            'role' => SocialGroupMember::ROLE_MEMBER,
            'joined_at' => now(),
        ]);

        $invite = SocialGroupInvite::create([
            'social_group_id' => $group->id,
            'invited_by' => $owner->id,
            'invited_user_id' => $invitee->id,
            'status' => SocialGroupInvite::STATUS_PENDING,
        ]);

        $revokeResponse = $this->actingAs($groupAdmin)->post(route('social-space.invites.revoke', [$group, $invite]));

        $revokeResponse->assertRedirect();

        $this->assertDatabaseHas('social_group_invites', [
            'id' => $invite->id,
            'status' => SocialGroupInvite::STATUS_REVOKED,
        ]);

        $promoteResponse = $this->actingAs($groupAdmin)->patch(route('social-space.members.role', [$group, $memberRecord]), [
            'role' => SocialGroupMember::ROLE_ADMIN,
        ]);

        $promoteResponse->assertRedirect();

        $this->assertDatabaseHas('social_group_members', [
            'id' => $memberRecord->id,
            'role' => SocialGroupMember::ROLE_ADMIN,
        ]);

        $transferResponse = $this->actingAs($groupAdmin)->post(route('social-space.members.transfer-ownership', [$group, $memberRecord]));

        $transferResponse->assertForbidden();

        $this->assertDatabaseHas('social_group_members', [
            'social_group_id' => $group->id,
            'user_id' => $owner->id,
            'role' => SocialGroupMember::ROLE_OWNER,
        ]);
    }

    #[Test]
    public function moderator_can_transfer_ownership_even_if_not_member(): void
    {
        $owner = $this->createApprovedUser('Admin');
        $moderator = $this->createApprovedUser('HR');
        $targetMember = $this->createApprovedUser('Admin');

        $group = SocialGroup::create([
            'name' => 'Ops Moderator Transfer',
            'description' => null,
            'visibility' => SocialGroup::VISIBILITY_PRIVATE,
            'campaign_id' => null,
            'created_by' => $owner->id,
            'is_archived' => false,
        ]);

        $ownerRecord = SocialGroupMember::create([
            'social_group_id' => $group->id,
            'user_id' => $owner->id,
            'role' => SocialGroupMember::ROLE_OWNER,
            'joined_at' => now(),
        ]);

        $targetRecord = SocialGroupMember::create([
            'social_group_id' => $group->id,
            'user_id' => $targetMember->id,
            'role' => SocialGroupMember::ROLE_MEMBER,
            'joined_at' => now(),
        ]);

        $response = $this->actingAs($moderator)->post(route('social-space.members.transfer-ownership', [$group, $targetRecord]));

        $response->assertRedirect();

        $this->assertDatabaseHas('social_group_members', [
            'id' => $ownerRecord->id,
            'role' => SocialGroupMember::ROLE_ADMIN,
        ]);

        $this->assertDatabaseHas('social_group_members', [
            'id' => $targetRecord->id,
            'role' => SocialGroupMember::ROLE_OWNER,
        ]);
    }

    #[Test]
    public function it_starts_direct_thread_between_two_active_approved_users(): void
    {
        $sender = $this->createApprovedUser('Admin');
        $recipient = $this->createApprovedUser('Admin');

        $response = $this->actingAs($sender)->post(route('social-space.people.start', $recipient));

        $response->assertRedirect();

        $thread = SocialDirectThread::query()->first();
        $this->assertNotNull($thread);

        $this->assertDatabaseHas('social_direct_thread_participants', [
            'social_direct_thread_id' => $thread->id,
            'user_id' => $sender->id,
        ]);

        $this->assertDatabaseHas('social_direct_thread_participants', [
            'social_direct_thread_id' => $thread->id,
            'user_id' => $recipient->id,
        ]);
    }

    #[Test]
    public function it_reuses_existing_direct_thread_for_same_pair(): void
    {
        $sender = $this->createApprovedUser('Admin');
        $recipient = $this->createApprovedUser('Admin');

        $firstResponse = $this->actingAs($sender)->post(route('social-space.people.start', $recipient));
        $firstResponse->assertRedirect();

        $secondResponse = $this->actingAs($sender)->post(route('social-space.people.start', $recipient));
        $secondResponse->assertRedirect();

        $this->assertSame(1, SocialDirectThread::query()->count());
        $this->assertSame(2, SocialDirectThreadParticipant::query()->count());
    }

    #[Test]
    public function it_denies_starting_direct_thread_with_self(): void
    {
        $sender = $this->createApprovedUser('Admin');

        $response = $this->actingAs($sender)->post(route('social-space.people.start', $sender));

        $response->assertSessionHasErrors('chat');
        $this->assertSame(0, SocialDirectThread::query()->count());
    }

    #[Test]
    public function it_denies_starting_direct_thread_with_inactive_or_unapproved_user(): void
    {
        $sender = $this->createApprovedUser('Admin');

        $inactiveTarget = User::factory()->create([
            'role' => 'Agent',
            'is_active' => false,
            'is_approved' => true,
        ]);

        $unapprovedTarget = User::factory()->create([
            'role' => 'Agent',
            'is_active' => true,
            'is_approved' => false,
        ]);

        $inactiveResponse = $this->actingAs($sender)->post(route('social-space.people.start', $inactiveTarget));
        $inactiveResponse->assertSessionHasErrors('chat');

        $unapprovedResponse = $this->actingAs($sender)->post(route('social-space.people.start', $unapprovedTarget));
        $unapprovedResponse->assertSessionHasErrors('chat');

        $this->assertSame(0, SocialDirectThread::query()->count());
    }

    #[Test]
    public function it_includes_campaign_metadata_in_people_payload_for_new_message_filtering(): void
    {
        $viewer = $this->createApprovedUser('Admin');
        $campaign = Campaign::factory()->create(['name' => 'Alpha Campaign']);

        $personWithCampaign = User::factory()->create([
            'first_name' => 'Aaron',
            'last_name' => 'Campaign',
            'role' => 'Agent',
            'is_approved' => true,
            'is_active' => true,
        ]);

        EmployeeSchedule::factory()->create([
            'user_id' => $personWithCampaign->id,
            'site_id' => Site::factory()->create()->id,
            'campaign_id' => $campaign->id,
            'is_active' => true,
        ]);

        User::factory()->create([
            'first_name' => 'Zed',
            'last_name' => 'NoCampaign',
            'role' => 'Agent',
            'is_approved' => true,
            'is_active' => true,
        ]);

        $response = $this->actingAs($viewer)->get(route('social-space.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('SocialSpace/Index')
            ->where('people.0.first_name', 'Aaron')
            ->where('people.0.campaign_id', $campaign->id)
            ->where('people.0.campaign_name', 'Alpha Campaign')
            ->where('people.1.first_name', 'Zed')
            ->where('people.1.campaign_id', null)
            ->where('people.1.campaign_name', null)
        );
    }

    #[Test]
    public function group_member_can_update_group_theme(): void
    {
        $owner = $this->createApprovedUser('Admin');
        $member = $this->createApprovedUser('Admin');

        $group = SocialGroup::create([
            'name' => 'Theme Group',
            'description' => null,
            'visibility' => SocialGroup::VISIBILITY_PRIVATE,
            'campaign_id' => null,
            'created_by' => $owner->id,
            'is_archived' => false,
        ]);

        SocialGroupMember::query()->insert([
            [
                'social_group_id' => $group->id,
                'user_id' => $owner->id,
                'role' => SocialGroupMember::ROLE_OWNER,
                'joined_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'social_group_id' => $group->id,
                'user_id' => $member->id,
                'role' => SocialGroupMember::ROLE_MEMBER,
                'joined_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $response = $this->actingAs($member)->patch(route('social-space.groups.theme.update', $group), [
            'theme_color' => '#123ABC',
            'theme_background' => 'ocean',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $this->assertDatabaseHas('social_groups', [
            'id' => $group->id,
            'theme_color' => '#123ABC',
            'theme_background' => 'ocean',
        ]);
    }

    #[Test]
    public function non_member_cannot_update_group_theme(): void
    {
        $owner = $this->createApprovedUser('Admin');
        $outsider = $this->createApprovedUser('Admin');

        $group = SocialGroup::create([
            'name' => 'Theme Guard Group',
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

        $response = $this->actingAs($outsider)->patch(route('social-space.groups.theme.update', $group), [
            'theme_color' => '#ABC123',
            'theme_background' => 'forest',
        ]);

        $response->assertForbidden();

        $this->assertDatabaseMissing('social_groups', [
            'id' => $group->id,
            'theme_color' => '#ABC123',
            'theme_background' => 'forest',
        ]);
    }

    #[Test]
    public function direct_thread_participant_can_update_theme(): void
    {
        $sender = $this->createApprovedUser('Admin');
        $recipient = $this->createApprovedUser('Admin');

        $thread = SocialDirectThread::create([
            'created_by' => $sender->id,
            'last_message_at' => now(),
        ]);

        SocialDirectThreadParticipant::query()->insert([
            [
                'social_direct_thread_id' => $thread->id,
                'user_id' => $sender->id,
                'joined_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'social_direct_thread_id' => $thread->id,
                'user_id' => $recipient->id,
                'joined_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $response = $this->actingAs($sender)->patch(route('social-space.threads.theme.update', $thread), [
            'theme_color' => '#0F4C81',
            'theme_background' => 'sunset',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $this->assertDatabaseHas('social_direct_threads', [
            'id' => $thread->id,
            'theme_color' => '#0F4C81',
            'theme_background' => 'sunset',
        ]);
    }

    #[Test]
    public function non_participant_cannot_update_direct_thread_theme(): void
    {
        $sender = $this->createApprovedUser('Admin');
        $recipient = $this->createApprovedUser('Admin');
        $outsider = $this->createApprovedUser('Admin');

        $thread = SocialDirectThread::create([
            'created_by' => $sender->id,
            'last_message_at' => now(),
        ]);

        SocialDirectThreadParticipant::query()->insert([
            [
                'social_direct_thread_id' => $thread->id,
                'user_id' => $sender->id,
                'joined_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'social_direct_thread_id' => $thread->id,
                'user_id' => $recipient->id,
                'joined_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $response = $this->actingAs($outsider)->patch(route('social-space.threads.theme.update', $thread), [
            'theme_color' => '#1F2937',
            'theme_background' => 'carbon',
        ]);

        $response->assertForbidden();

        $this->assertDatabaseMissing('social_direct_threads', [
            'id' => $thread->id,
            'theme_color' => '#1F2937',
            'theme_background' => 'carbon',
        ]);
    }

    #[Test]
    public function it_includes_conversation_theme_fields_in_social_space_payload(): void
    {
        $viewer = $this->createApprovedUser('Admin');
        $otherUser = $this->createApprovedUser('Admin');

        $group = SocialGroup::create([
            'name' => 'Payload Theme Group',
            'description' => null,
            'visibility' => SocialGroup::VISIBILITY_PUBLIC,
            'campaign_id' => null,
            'created_by' => $viewer->id,
            'is_archived' => false,
            'theme_color' => '#112233',
            'theme_background' => 'aurora',
        ]);

        SocialGroupMember::create([
            'social_group_id' => $group->id,
            'user_id' => $viewer->id,
            'role' => SocialGroupMember::ROLE_OWNER,
            'joined_at' => now(),
        ]);

        $thread = SocialDirectThread::create([
            'created_by' => $viewer->id,
            'last_message_at' => now(),
            'theme_color' => '#445566',
            'theme_background' => 'forest',
        ]);

        SocialDirectThreadParticipant::query()->insert([
            [
                'social_direct_thread_id' => $thread->id,
                'user_id' => $viewer->id,
                'joined_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'social_direct_thread_id' => $thread->id,
                'user_id' => $otherUser->id,
                'joined_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $response = $this->actingAs($viewer)->get(route('social-space.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('SocialSpace/Index')
            ->where('groupConversations.0.theme_color', '#112233')
            ->where('groupConversations.0.theme_background', 'aurora')
            ->where('directConversations.0.theme_color', '#445566')
            ->where('directConversations.0.theme_background', 'forest')
        );
    }

    #[Test]
    public function group_member_can_upload_custom_theme_background_image(): void
    {
        Storage::fake('public');

        $owner = $this->createApprovedUser('Admin');
        $member = $this->createApprovedUser('Admin');

        $group = SocialGroup::create([
            'name' => 'Image Theme Group',
            'description' => null,
            'visibility' => SocialGroup::VISIBILITY_PRIVATE,
            'campaign_id' => null,
            'created_by' => $owner->id,
            'is_archived' => false,
        ]);

        SocialGroupMember::query()->insert([
            [
                'social_group_id' => $group->id,
                'user_id' => $owner->id,
                'role' => SocialGroupMember::ROLE_OWNER,
                'joined_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'social_group_id' => $group->id,
                'user_id' => $member->id,
                'role' => SocialGroupMember::ROLE_MEMBER,
                'joined_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $response = $this->actingAs($member)->patch(route('social-space.groups.theme.update', $group), [
            'theme_color' => '#2244AA',
            'theme_background' => 'image',
            'background_image' => UploadedFile::fake()->image('wallpaper.jpg', 1200, 700),
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $group->refresh();

        $this->assertSame('image', $group->theme_background);
        $this->assertNotNull($group->theme_background_image_path);
        Storage::disk('public')->assertExists($group->theme_background_image_path);

        $payload = $this->actingAs($member)->get(route('social-space.index'));
        $payload->assertInertia(fn ($page) => $page
            ->component('SocialSpace/Index')
            ->where('groupConversations.0.theme_background', 'image')
            ->where('groupConversations.0.theme_background_image_url', $group->theme_background_image_url)
        );
    }

    #[Test]
    public function non_participant_cannot_send_direct_message(): void
    {
        $sender = $this->createApprovedUser('Admin');
        $recipient = $this->createApprovedUser('Admin');
        $outsider = $this->createApprovedUser('Admin');

        $thread = SocialDirectThread::create([
            'created_by' => $sender->id,
            'last_message_at' => now(),
        ]);

        SocialDirectThreadParticipant::create([
            'social_direct_thread_id' => $thread->id,
            'user_id' => $sender->id,
            'joined_at' => now(),
        ]);

        SocialDirectThreadParticipant::create([
            'social_direct_thread_id' => $thread->id,
            'user_id' => $recipient->id,
            'joined_at' => now(),
        ]);

        $response = $this->actingAs($outsider)->post(route('social-space.threads.messages.store', $thread), [
            'message' => 'Should be rejected',
        ]);

        $response->assertForbidden();
        $this->assertSame(0, SocialDirectMessage::query()->count());
    }

    #[Test]
    public function participant_sending_direct_message_updates_own_read_marker(): void
    {
        $sender = $this->createApprovedUser('Admin');
        $recipient = $this->createApprovedUser('Admin');

        $thread = SocialDirectThread::create([
            'created_by' => $sender->id,
            'last_message_at' => now(),
        ]);

        $senderParticipant = SocialDirectThreadParticipant::create([
            'social_direct_thread_id' => $thread->id,
            'user_id' => $sender->id,
            'joined_at' => now(),
            'last_read_at' => null,
        ]);

        SocialDirectThreadParticipant::create([
            'social_direct_thread_id' => $thread->id,
            'user_id' => $recipient->id,
            'joined_at' => now(),
            'last_read_at' => null,
        ]);

        $response = $this->actingAs($sender)->post(route('social-space.threads.messages.store', $thread), [
            'message' => 'Hello directly',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $senderParticipant->refresh();
        $thread->refresh();

        $this->assertNotNull($senderParticipant->last_read_at);
        $this->assertNotNull($thread->last_message_at);

        $this->assertDatabaseHas('social_direct_messages', [
            'social_direct_thread_id' => $thread->id,
            'user_id' => $sender->id,
            'message' => 'Hello directly',
        ]);
    }

    #[Test]
    public function it_creates_group_direct_thread_with_selected_participants(): void
    {
        $creator = $this->createApprovedUser('Admin');
        $participantA = $this->createApprovedUser('Admin');
        $participantB = $this->createApprovedUser('Admin');

        $response = $this->actingAs($creator)->post(route('social-space.threads.group.store'), [
            'name' => 'Night Shift Group',
            'participant_ids' => [$participantA->id, $participantB->id],
        ]);

        $response->assertRedirect();

        $thread = SocialDirectThread::query()->where('name', 'Night Shift Group')->first();

        $this->assertNotNull($thread);
        $this->assertTrue((bool) $thread->is_group);

        $this->assertDatabaseHas('social_direct_thread_participants', [
            'social_direct_thread_id' => $thread->id,
            'user_id' => $creator->id,
        ]);

        $this->assertDatabaseHas('social_direct_thread_participants', [
            'social_direct_thread_id' => $thread->id,
            'user_id' => $participantA->id,
        ]);

        $this->assertDatabaseHas('social_direct_thread_participants', [
            'social_direct_thread_id' => $thread->id,
            'user_id' => $participantB->id,
        ]);
    }

    #[Test]
    public function author_can_update_and_delete_group_message(): void
    {
        $author = $this->createApprovedUser('Admin');

        $group = SocialGroup::create([
            'name' => 'Edit Test Group',
            'description' => null,
            'visibility' => SocialGroup::VISIBILITY_PUBLIC,
            'campaign_id' => null,
            'created_by' => $author->id,
            'is_archived' => false,
        ]);

        SocialGroupMember::create([
            'social_group_id' => $group->id,
            'user_id' => $author->id,
            'role' => SocialGroupMember::ROLE_OWNER,
            'joined_at' => now(),
        ]);

        $message = SocialMessage::create([
            'social_group_id' => $group->id,
            'user_id' => $author->id,
            'message' => 'Original text',
        ]);

        $updateResponse = $this->actingAs($author)->patch(route('social-space.messages.update', [$group, $message]), [
            'message' => 'Edited text',
        ]);

        $updateResponse->assertRedirect();

        $this->assertDatabaseHas('social_messages', [
            'id' => $message->id,
            'message' => 'Edited text',
        ]);

        $message->refresh();
        $this->assertNotNull($message->edited_at);

        $deleteResponse = $this->actingAs($author)->delete(route('social-space.messages.destroy', [$group, $message]));

        $deleteResponse->assertRedirect();

        $this->assertSoftDeleted('social_messages', [
            'id' => $message->id,
        ]);
    }

    #[Test]
    public function it_toggles_group_message_reaction_for_member(): void
    {
        $author = $this->createApprovedUser('Admin');
        $reactor = $this->createApprovedUser('Admin');

        $group = SocialGroup::create([
            'name' => 'Reaction Group',
            'description' => null,
            'visibility' => SocialGroup::VISIBILITY_PUBLIC,
            'campaign_id' => null,
            'created_by' => $author->id,
            'is_archived' => false,
        ]);

        SocialGroupMember::query()->insert([
            [
                'social_group_id' => $group->id,
                'user_id' => $author->id,
                'role' => SocialGroupMember::ROLE_OWNER,
                'joined_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'social_group_id' => $group->id,
                'user_id' => $reactor->id,
                'role' => SocialGroupMember::ROLE_MEMBER,
                'joined_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $message = SocialMessage::create([
            'social_group_id' => $group->id,
            'user_id' => $author->id,
            'message' => 'React to this',
        ]);

        $firstToggle = $this->actingAs($reactor)->post(route('social-space.messages.reactions.toggle', [$group, $message]), [
            'emoji' => '👍',
        ]);

        $firstToggle->assertOk();

        $this->assertDatabaseHas('social_reactions', [
            'reactable_type' => SocialMessage::class,
            'reactable_id' => $message->id,
            'user_id' => $reactor->id,
            'emoji' => '👍',
        ]);

        $secondToggle = $this->actingAs($reactor)->post(route('social-space.messages.reactions.toggle', [$group, $message]), [
            'emoji' => '👍',
        ]);

        $secondToggle->assertOk();

        $this->assertDatabaseMissing('social_reactions', [
            'reactable_type' => SocialMessage::class,
            'reactable_id' => $message->id,
            'user_id' => $reactor->id,
            'emoji' => '👍',
        ]);
    }

    #[Test]
    public function it_toggles_direct_message_reaction_for_participant(): void
    {
        $author = $this->createApprovedUser('Admin');
        $reactor = $this->createApprovedUser('Admin');

        $thread = SocialDirectThread::create([
            'created_by' => $author->id,
            'last_message_at' => now(),
        ]);

        SocialDirectThreadParticipant::query()->insert([
            [
                'social_direct_thread_id' => $thread->id,
                'user_id' => $author->id,
                'joined_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'social_direct_thread_id' => $thread->id,
                'user_id' => $reactor->id,
                'joined_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $message = SocialDirectMessage::create([
            'social_direct_thread_id' => $thread->id,
            'user_id' => $author->id,
            'message' => 'Direct reaction test',
        ]);

        $toggle = $this->actingAs($reactor)->post(route('social-space.threads.messages.reactions.toggle', [$thread, $message]), [
            'emoji' => '❤️',
        ]);

        $toggle->assertOk();

        $this->assertDatabaseHas('social_reactions', [
            'reactable_type' => SocialDirectMessage::class,
            'reactable_id' => $message->id,
            'user_id' => $reactor->id,
            'emoji' => '❤️',
        ]);
    }

    #[Test]
    public function participant_can_download_direct_attachment_and_outsider_is_forbidden(): void
    {
        Storage::fake('local');

        $sender = $this->createApprovedUser('Admin');
        $recipient = $this->createApprovedUser('Admin');
        $outsider = $this->createApprovedUser('Admin');

        $thread = SocialDirectThread::create([
            'created_by' => $sender->id,
            'last_message_at' => now(),
        ]);

        SocialDirectThreadParticipant::query()->insert([
            [
                'social_direct_thread_id' => $thread->id,
                'user_id' => $sender->id,
                'joined_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'social_direct_thread_id' => $thread->id,
                'user_id' => $recipient->id,
                'joined_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $sendResponse = $this->actingAs($sender)->post(route('social-space.threads.messages.store', $thread), [
            'message' => 'Attachment message',
            'attachments' => [UploadedFile::fake()->create('secret.txt', 5, 'text/plain')],
        ]);

        $sendResponse->assertRedirect();

        $directMessage = SocialDirectMessage::query()->latest('id')->first();
        $this->assertNotNull($directMessage);

        $attachment = $directMessage->attachments()->first();
        $this->assertNotNull($attachment);

        $participantDownload = $this->actingAs($recipient)->get(route('social-space.attachments.download', $attachment));
        $participantDownload->assertOk();
        $participantDownload->assertDownload('secret.txt');

        $forbiddenDownload = $this->actingAs($outsider)->get(route('social-space.attachments.download', $attachment));
        $forbiddenDownload->assertForbidden();
    }

    #[Test]
    public function it_stores_group_message_with_parent_reference(): void
    {
        $author = $this->createApprovedUser('Admin');
        $replier = $this->createApprovedUser('Admin');

        $group = SocialGroup::create([
            'name' => 'Reply Group',
            'description' => null,
            'visibility' => SocialGroup::VISIBILITY_PUBLIC,
            'campaign_id' => null,
            'created_by' => $author->id,
            'is_archived' => false,
        ]);

        SocialGroupMember::create([
            'social_group_id' => $group->id,
            'user_id' => $author->id,
            'role' => SocialGroupMember::ROLE_OWNER,
            'joined_at' => now(),
        ]);

        $parent = SocialMessage::create([
            'social_group_id' => $group->id,
            'user_id' => $author->id,
            'message' => 'Original ping',
        ]);

        $response = $this->actingAs($replier)->post(route('social-space.messages.store', $group), [
            'message' => 'Replying here',
            'parent_id' => $parent->id,
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $this->assertDatabaseHas('social_messages', [
            'social_group_id' => $group->id,
            'user_id' => $replier->id,
            'parent_id' => $parent->id,
            'message' => 'Replying here',
        ]);
    }

    #[Test]
    public function it_rejects_group_reply_when_parent_belongs_to_a_different_group(): void
    {
        $author = $this->createApprovedUser('Admin');

        $groupA = SocialGroup::create([
            'name' => 'Group A',
            'description' => null,
            'visibility' => SocialGroup::VISIBILITY_PUBLIC,
            'campaign_id' => null,
            'created_by' => $author->id,
            'is_archived' => false,
        ]);

        $groupB = SocialGroup::create([
            'name' => 'Group B',
            'description' => null,
            'visibility' => SocialGroup::VISIBILITY_PUBLIC,
            'campaign_id' => null,
            'created_by' => $author->id,
            'is_archived' => false,
        ]);

        SocialGroupMember::create([
            'social_group_id' => $groupA->id,
            'user_id' => $author->id,
            'role' => SocialGroupMember::ROLE_OWNER,
            'joined_at' => now(),
        ]);
        SocialGroupMember::create([
            'social_group_id' => $groupB->id,
            'user_id' => $author->id,
            'role' => SocialGroupMember::ROLE_OWNER,
            'joined_at' => now(),
        ]);

        $foreignParent = SocialMessage::create([
            'social_group_id' => $groupA->id,
            'user_id' => $author->id,
            'message' => 'Belongs to A',
        ]);

        $response = $this->actingAs($author)->post(route('social-space.messages.store', $groupB), [
            'message' => 'Trying to reply cross-group',
            'parent_id' => $foreignParent->id,
        ]);

        $response->assertSessionHasErrors('parent_id');
        $this->assertDatabaseMissing('social_messages', [
            'social_group_id' => $groupB->id,
            'parent_id' => $foreignParent->id,
        ]);
    }

    #[Test]
    public function it_stores_direct_message_with_parent_reference(): void
    {
        $sender = $this->createApprovedUser('Admin');
        $recipient = $this->createApprovedUser('Admin');

        $thread = SocialDirectThread::create([
            'created_by' => $sender->id,
            'last_message_at' => now(),
        ]);

        SocialDirectThreadParticipant::create([
            'social_direct_thread_id' => $thread->id,
            'user_id' => $sender->id,
            'joined_at' => now(),
        ]);
        SocialDirectThreadParticipant::create([
            'social_direct_thread_id' => $thread->id,
            'user_id' => $recipient->id,
            'joined_at' => now(),
        ]);

        $parent = SocialDirectMessage::create([
            'social_direct_thread_id' => $thread->id,
            'user_id' => $recipient->id,
            'message' => 'What time?',
        ]);

        $response = $this->actingAs($sender)->post(route('social-space.threads.messages.store', $thread), [
            'message' => 'At three',
            'parent_id' => $parent->id,
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $this->assertDatabaseHas('social_direct_messages', [
            'social_direct_thread_id' => $thread->id,
            'user_id' => $sender->id,
            'parent_id' => $parent->id,
            'message' => 'At three',
        ]);
    }

    #[Test]
    public function it_rejects_direct_reply_when_parent_belongs_to_a_different_thread(): void
    {
        $sender = $this->createApprovedUser('Admin');
        $recipient = $this->createApprovedUser('Admin');

        $threadA = SocialDirectThread::create(['created_by' => $sender->id, 'last_message_at' => now()]);
        $threadB = SocialDirectThread::create(['created_by' => $sender->id, 'last_message_at' => now()]);

        foreach ([$threadA, $threadB] as $thread) {
            SocialDirectThreadParticipant::create([
                'social_direct_thread_id' => $thread->id,
                'user_id' => $sender->id,
                'joined_at' => now(),
            ]);
            SocialDirectThreadParticipant::create([
                'social_direct_thread_id' => $thread->id,
                'user_id' => $recipient->id,
                'joined_at' => now(),
            ]);
        }

        $foreignParent = SocialDirectMessage::create([
            'social_direct_thread_id' => $threadA->id,
            'user_id' => $recipient->id,
            'message' => 'Belongs to A',
        ]);

        $response = $this->actingAs($sender)->post(route('social-space.threads.messages.store', $threadB), [
            'message' => 'Trying to reply cross-thread',
            'parent_id' => $foreignParent->id,
        ]);

        $response->assertSessionHasErrors('parent_id');
        $this->assertDatabaseMissing('social_direct_messages', [
            'social_direct_thread_id' => $threadB->id,
            'parent_id' => $foreignParent->id,
        ]);
    }

    #[Test]
    public function it_keeps_direct_reply_visible_when_parent_is_soft_deleted(): void
    {
        $sender = $this->createApprovedUser('Admin');
        $recipient = $this->createApprovedUser('Admin');

        $thread = SocialDirectThread::create(['created_by' => $sender->id, 'last_message_at' => now()]);
        SocialDirectThreadParticipant::create([
            'social_direct_thread_id' => $thread->id,
            'user_id' => $sender->id,
            'joined_at' => now(),
        ]);
        SocialDirectThreadParticipant::create([
            'social_direct_thread_id' => $thread->id,
            'user_id' => $recipient->id,
            'joined_at' => now(),
        ]);

        $parent = SocialDirectMessage::create([
            'social_direct_thread_id' => $thread->id,
            'user_id' => $recipient->id,
            'message' => 'Original',
        ]);

        $reply = SocialDirectMessage::create([
            'social_direct_thread_id' => $thread->id,
            'user_id' => $sender->id,
            'parent_id' => $parent->id,
            'message' => 'Reply',
        ]);

        $parent->delete();

        $reply->refresh()->load('parent');

        $this->assertNotNull($reply->parent);
        $this->assertSame($parent->id, $reply->parent->id);
        $this->assertNotNull($reply->parent->deleted_at);
    }
}
