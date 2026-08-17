<?php

namespace App\Http\Controllers;

use App\Events\SocialDirectMessageCreated;
use App\Events\SocialDirectMessageUpdated;
use App\Events\SocialGroupMessageCreated;
use App\Events\SocialGroupMessageUpdated;
use App\Http\Requests\StoreSocialDirectGroupThreadRequest;
use App\Http\Requests\StoreSocialGroupInviteRequest;
use App\Http\Requests\StoreSocialGroupRequest;
use App\Http\Requests\StoreSocialMessageRequest;
use App\Http\Requests\ToggleSocialReactionRequest;
use App\Http\Requests\UpdateSocialConversationThemeRequest;
use App\Http\Requests\UpdateSocialMessageRequest;
use App\Models\SocialAttachment;
use App\Models\SocialDirectMessage;
use App\Models\SocialDirectThread;
use App\Models\SocialDirectThreadParticipant;
use App\Models\SocialGroup;
use App\Models\SocialGroupInvite;
use App\Models\SocialGroupMember;
use App\Models\SocialMessage;
use App\Models\SocialReaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class SocialSpaceController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', SocialGroup::class);

        $user = $request->user();
        $campaignIds = $user->getCampaignIds();
        $tab = $request->string('tab')->toString();
        $groupBeforeId = $request->integer('group_before_id') ?: null;
        $threadBeforeId = $request->integer('thread_before_id') ?: null;

        if (! in_array($tab, ['all', 'unread', 'people', 'groups'], true)) {
            $tab = 'all';
        }

        $groups = SocialGroup::query()
            ->with(['creator:id,first_name,last_name'])
            ->withCount('members')
            ->where('is_archived', false)
            ->where(function ($query) use ($user, $campaignIds) {
                $query->where('visibility', SocialGroup::VISIBILITY_PUBLIC)
                    ->orWhereHas('memberRecords', fn ($q) => $q->where('user_id', $user->id));

                if (! empty($campaignIds)) {
                    $query->orWhere(function ($campaignQuery) use ($campaignIds) {
                        $campaignQuery->where('visibility', SocialGroup::VISIBILITY_CAMPAIGN)
                            ->whereIn('campaign_id', $campaignIds);
                    });
                }
            })
            ->orderBy('name')
            ->get();

        $latestGroupMessages = SocialMessage::query()
            ->with('user:id,first_name,last_name')
            ->whereIn('social_group_id', $groups->pluck('id'))
            ->latest('id')
            ->get()
            ->unique('social_group_id')
            ->keyBy('social_group_id');

        $memberRecords = SocialGroupMember::query()
            ->where('user_id', $user->id)
            ->whereIn('social_group_id', $groups->pluck('id'))
            ->get()
            ->keyBy('social_group_id');

        $groupConversations = $groups->map(function (SocialGroup $group) use ($latestGroupMessages, $memberRecords) {
            $latestMessage = $latestGroupMessages->get($group->id);
            $membership = $memberRecords->get($group->id);

            $unreadCount = 0;
            if ($latestMessage && $membership && ($membership->last_read_at === null || $latestMessage->created_at->gt($membership->last_read_at))) {
                $unreadCount = 1;
            }

            return [
                'id' => $group->id,
                'name' => $group->name,
                'type' => 'group',
                'visibility' => $group->visibility,
                'members_count' => $group->members_count,
                'theme_color' => $group->theme_color,
                'theme_background' => $group->theme_background,
                'theme_background_image_url' => $group->theme_background_image_url,
                'last_message' => $latestMessage?->message,
                'last_message_at' => $latestMessage?->created_at?->toIso8601String(),
                'last_message_sender' => $latestMessage?->user ? [
                    'id' => $latestMessage->user->id,
                    'first_name' => $latestMessage->user->first_name,
                    'last_name' => $latestMessage->user->last_name,
                ] : null,
                'unread_count' => $unreadCount,
                'is_pinned' => (bool) ($membership?->is_pinned ?? false),
            ];
        })->values();

        $directThreads = SocialDirectThread::query()
            ->whereHas('participants', fn ($query) => $query->where('user_id', $user->id))
            ->with([
                'participants.user:id,first_name,last_name,role,is_active,is_approved,avatar',
                'latestMessage.user:id,first_name,last_name',
            ])
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->get();

        $directConversations = $directThreads->map(function (SocialDirectThread $thread) use ($user) {
            $otherParticipant = $thread->participants->firstWhere('user_id', '!=', $user->id);
            $selfParticipant = $thread->participants->firstWhere('user_id', $user->id);
            $latestMessage = $thread->latestMessage;

            $unreadCount = 0;
            if ($latestMessage && $selfParticipant && ($selfParticipant->last_read_at === null || $latestMessage->created_at->gt($selfParticipant->last_read_at))) {
                $unreadCount = 1;
            }

            return [
                'id' => $thread->id,
                'type' => 'direct',
                'name' => $thread->name,
                'is_group' => $thread->is_group,
                'image_url' => $thread->image_url,
                'theme_color' => $thread->theme_color,
                'theme_background' => $thread->theme_background,
                'theme_background_image_url' => $thread->theme_background_image_url,
                'other_user' => $otherParticipant?->user ? [
                    'id' => $otherParticipant->user->id,
                    'first_name' => $otherParticipant->user->first_name,
                    'last_name' => $otherParticipant->user->last_name,
                    'role' => $otherParticipant->user->role,
                    'avatar_url' => $otherParticipant->user->avatar_url,
                ] : null,
                'participants' => $thread->participants->map(function (SocialDirectThreadParticipant $participant) {
                    return [
                        'id' => $participant->user?->id,
                        'first_name' => $participant->user?->first_name,
                        'last_name' => $participant->user?->last_name,
                        'role' => $participant->user?->role,
                        'avatar_url' => $participant->user?->avatar_url,
                        'last_read_at' => $participant->last_read_at?->toIso8601String(),
                    ];
                })->filter(fn (?array $participant) => ! empty($participant['id']))->values(),
                'last_message' => $latestMessage?->message,
                'last_message_at' => $latestMessage?->created_at?->toIso8601String(),
                'last_message_sender' => $latestMessage?->user ? [
                    'id' => $latestMessage->user->id,
                    'first_name' => $latestMessage->user->first_name,
                    'last_name' => $latestMessage->user->last_name,
                ] : null,
                'unread_count' => $unreadCount,
                'is_pinned' => (bool) ($selfParticipant?->is_pinned ?? false),
            ];
        })->values();

        $directThreadByOtherUserId = collect($directConversations)
            ->filter(fn (array $conversation) => isset($conversation['other_user']['id']))
            ->mapWithKeys(fn (array $conversation) => [$conversation['other_user']['id'] => $conversation['id']]);

        $people = User::query()
            ->with([
                'activeSchedule:id,user_id,campaign_id',
                'activeSchedule.campaign:id,name',
            ])
            ->where('is_approved', true)
            ->where('is_active', true)
            ->where('id', '!=', $user->id)
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'role', 'avatar'])
            ->map(function (User $person) use ($directThreadByOtherUserId) {
                return [
                    'id' => $person->id,
                    'first_name' => $person->first_name,
                    'last_name' => $person->last_name,
                    'role' => $person->role,
                    'avatar_url' => $person->avatar_url,
                    'campaign_id' => $person->activeSchedule?->campaign_id,
                    'campaign_name' => $person->activeSchedule?->campaign?->name,
                    'direct_thread_id' => $directThreadByOtherUserId->get($person->id),
                ];
            })
            ->values();

        $selectedGroup = null;
        $messages = [];
        $selectedDirectThread = null;
        $directMessages = [];
        $inviteCandidates = [];
        $selectedGroupMembers = [];
        $selectedGroupPendingInvites = [];
        $canManageSelectedGroup = false;
        $groupMessageCursor = [
            'has_more' => false,
            'next_before_id' => null,
        ];
        $directMessageCursor = [
            'has_more' => false,
            'next_before_id' => null,
        ];
        $directReadMarkers = [];

        $pendingInvites = SocialGroupInvite::query()
            ->with(['group:id,name,visibility', 'inviter:id,first_name,last_name'])
            ->where('invited_user_id', $user->id)
            ->where('status', SocialGroupInvite::STATUS_PENDING)
            ->latest('id')
            ->get();

        if ($request->filled('group_id')) {
            $selectedGroup = SocialGroup::query()
                ->with(['members:id,first_name,last_name,avatar', 'campaign:id,name'])
                ->find($request->integer('group_id'));

            if ($selectedGroup) {
                $this->authorize('view', $selectedGroup);
                $canManageSelectedGroup = $user->can('update', $selectedGroup);

                [$messages, $hasMoreGroupMessages, $nextGroupBeforeId] = $this->resolveGroupMessageWindow($selectedGroup, $groupBeforeId);

                $groupMessageCursor = [
                    'has_more' => $hasMoreGroupMessages,
                    'next_before_id' => $nextGroupBeforeId,
                ];

                SocialGroupMember::query()
                    ->where('social_group_id', $selectedGroup->id)
                    ->where('user_id', $user->id)
                    ->update(['last_read_at' => now()]);

                if ($canManageSelectedGroup) {
                    $selectedGroupMembers = $selectedGroup->memberRecords()
                        ->with('user:id,first_name,last_name')
                        ->orderByRaw("FIELD(role, 'owner', 'admin', 'member')")
                        ->orderBy('id')
                        ->get();

                    $selectedGroupPendingInvites = $selectedGroup->invites()
                        ->with(['invitedUser:id,first_name,last_name,role', 'inviter:id,first_name,last_name'])
                        ->where('status', SocialGroupInvite::STATUS_PENDING)
                        ->latest('id')
                        ->get();

                    $memberIds = $selectedGroup->memberRecords()->pluck('user_id');
                    $pendingInviteIds = $selectedGroup->invites()
                        ->where('status', SocialGroupInvite::STATUS_PENDING)
                        ->pluck('invited_user_id');

                    $excludedUserIds = $memberIds
                        ->merge($pendingInviteIds)
                        ->push($user->id)
                        ->unique()
                        ->values()
                        ->all();

                    $inviteCandidates = User::query()
                        ->where('is_approved', true)
                        ->where('is_active', true)
                        ->whereNotIn('id', $excludedUserIds)
                        ->orderBy('first_name')
                        ->orderBy('last_name')
                        ->get(['id', 'first_name', 'last_name', 'role']);
                }
            }
        }

        if ($request->filled('thread_id')) {
            $selectedDirectThread = SocialDirectThread::query()
                ->with([
                    'participants.user:id,first_name,last_name,role,avatar',
                ])
                ->find($request->integer('thread_id'));

            if ($selectedDirectThread) {
                $isParticipant = $selectedDirectThread->participants()->where('user_id', $user->id)->exists();
                if (! $isParticipant) {
                    abort(403);
                }

                [$directMessages, $hasMoreDirectMessages, $nextDirectBeforeId] = $this->resolveDirectMessageWindow($selectedDirectThread, $threadBeforeId);

                $directMessageCursor = [
                    'has_more' => $hasMoreDirectMessages,
                    'next_before_id' => $nextDirectBeforeId,
                ];

                $directReadMarkers = $selectedDirectThread->participants
                    ->map(function (SocialDirectThreadParticipant $participant) {
                        return [
                            'user_id' => $participant->user_id,
                            'name' => $participant->user ? trim($participant->user->first_name.' '.$participant->user->last_name) : 'Unknown',
                            'last_read_at' => $participant->last_read_at?->toIso8601String(),
                        ];
                    })
                    ->values();

                SocialDirectThreadParticipant::query()
                    ->where('social_direct_thread_id', $selectedDirectThread->id)
                    ->where('user_id', $user->id)
                    ->update(['last_read_at' => now()]);
            }
        }

        $groupUnreadCount = $groupConversations->sum('unread_count');
        $peopleUnreadCount = $directConversations->sum('unread_count');

        return Inertia::render('SocialSpace/Index', [
            'groups' => $groups,
            'groupConversations' => $groupConversations,
            'directConversations' => $directConversations,
            'people' => $people,
            'selectedGroup' => $selectedGroup,
            'messages' => $messages,
            'selectedDirectThread' => $selectedDirectThread,
            'directMessages' => $directMessages,
            'pendingInvites' => $pendingInvites,
            'inviteCandidates' => $inviteCandidates,
            'selectedGroupMembers' => $selectedGroupMembers,
            'selectedGroupPendingInvites' => $selectedGroupPendingInvites,
            'canManageSelectedGroup' => $canManageSelectedGroup,
            'canCreateGroup' => $user->hasPermission('social_space.create_group'),
            'currentUserId' => $user->id,
            'tab' => $tab,
            'groupMessageCursor' => $groupMessageCursor,
            'directMessageCursor' => $directMessageCursor,
            'directReadMarkers' => $directReadMarkers,
            'unreadCounts' => [
                'groups' => $groupUnreadCount,
                'people' => $peopleUnreadCount,
            ],
        ]);
    }

    private function resolveGroupMessageWindow(SocialGroup $group, ?int $beforeId): array
    {
        $query = SocialMessage::query()
            ->with([
                'user:id,first_name,last_name,avatar',
                'reactions.user:id,first_name,last_name',
                'attachments',
                'parent' => fn ($q) => $q->select(['id', 'user_id', 'message', 'deleted_at']),
                'parent.user:id,first_name,last_name',
            ])
            ->where('social_group_id', $group->id);

        if ($beforeId !== null) {
            $query->where('id', '<', $beforeId);
        }

        $rows = $query
            ->latest('id')
            ->limit(31)
            ->get();

        $hasMore = $rows->count() > 30;
        $window = $rows->take(30)->reverse()->values();

        return [$window, $hasMore, $hasMore ? $window->first()?->id : null];
    }

    private function resolveDirectMessageWindow(SocialDirectThread $thread, ?int $beforeId): array
    {
        $query = SocialDirectMessage::query()
            ->with([
                'user:id,first_name,last_name,avatar',
                'reactions.user:id,first_name,last_name',
                'attachments',
                'parent' => fn ($q) => $q->select(['id', 'user_id', 'message', 'deleted_at']),
                'parent.user:id,first_name,last_name',
            ])
            ->where('social_direct_thread_id', $thread->id);

        if ($beforeId !== null) {
            $query->where('id', '<', $beforeId);
        }

        $rows = $query
            ->latest('id')
            ->limit(31)
            ->get();

        $hasMore = $rows->count() > 30;
        $window = $rows->take(30)->reverse()->values();

        return [$window, $hasMore, $hasMore ? $window->first()?->id : null];
    }

    public function startDirectThread(User $userToChat, Request $request)
    {
        if (! $request->user()->hasPermission('social_space.message')) {
            abort(403);
        }

        if ($userToChat->id === $request->user()->id) {
            return back()->withErrors(['chat' => 'You cannot start a direct chat with yourself.']);
        }

        if (! $userToChat->is_approved || ! $userToChat->is_active) {
            return back()->withErrors(['chat' => 'You can only chat with active approved users.']);
        }

        $currentUserId = $request->user()->id;

        $thread = SocialDirectThread::query()
            ->whereHas('participants', fn ($query) => $query->where('user_id', $currentUserId))
            ->whereHas('participants', fn ($query) => $query->where('user_id', $userToChat->id))
            ->withCount('participants')
            ->having('participants_count', 2)
            ->first();

        if (! $thread) {
            $thread = DB::transaction(function () use ($request, $userToChat) {
                $created = SocialDirectThread::create([
                    'created_by' => $request->user()->id,
                ]);

                SocialDirectThreadParticipant::create([
                    'social_direct_thread_id' => $created->id,
                    'user_id' => $request->user()->id,
                    'joined_at' => now(),
                    'last_read_at' => now(),
                ]);

                SocialDirectThreadParticipant::create([
                    'social_direct_thread_id' => $created->id,
                    'user_id' => $userToChat->id,
                    'joined_at' => now(),
                ]);

                return $created;
            });
        }

        return redirect()->route('social-space.index', [
            'tab' => 'people',
            'thread_id' => $thread->id,
        ]);
    }

    public function storeDirectGroupThread(StoreSocialDirectGroupThreadRequest $request)
    {
        if (! $request->user()->hasPermission('social_space.message')) {
            abort(403);
        }

        $validated = $request->validated();
        $currentUserId = $request->user()->id;

        $participantIds = collect($validated['participant_ids'])
            ->map(fn (int|string $id) => (int) $id)
            ->push($currentUserId)
            ->unique()
            ->values();

        $participants = User::query()
            ->whereIn('id', $participantIds)
            ->where('is_approved', true)
            ->where('is_active', true)
            ->get();

        if ($participants->count() !== $participantIds->count()) {
            return back()->withErrors([
                'participant_ids' => 'Only active approved users can be included in a group chat.',
            ]);
        }

        $thread = DB::transaction(function () use ($validated, $currentUserId, $participantIds) {
            $created = SocialDirectThread::create([
                'created_by' => $currentUserId,
                'name' => $validated['name'],
                'is_group' => true,
                'last_message_at' => now(),
            ]);

            $rows = $participantIds->map(function (int $participantId) use ($created, $currentUserId) {
                return [
                    'social_direct_thread_id' => $created->id,
                    'user_id' => $participantId,
                    'joined_at' => now(),
                    'last_read_at' => $participantId === $currentUserId ? now() : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            })->all();

            SocialDirectThreadParticipant::query()->insert($rows);

            return $created;
        });

        return redirect()->route('social-space.index', [
            'tab' => 'people',
            'thread_id' => $thread->id,
        ])->with('flash', [
            'message' => 'Group chat created successfully.',
            'type' => 'success',
        ]);
    }

    public function invite(SocialGroup $socialGroup, StoreSocialGroupInviteRequest $request)
    {
        $this->authorize('manageInvites', $socialGroup);

        $validated = $request->validated();
        $inviter = $request->user();
        $invitedUserId = (int) $validated['invited_user_id'];

        if ($socialGroup->isMember($invitedUserId)) {
            return back()->withErrors([
                'invited_user_id' => 'User is already a group member.',
            ]);
        }

        $existingPendingInvite = SocialGroupInvite::query()
            ->where('social_group_id', $socialGroup->id)
            ->where('invited_user_id', $invitedUserId)
            ->where('status', SocialGroupInvite::STATUS_PENDING)
            ->first();

        if ($existingPendingInvite) {
            return back()->withErrors([
                'invited_user_id' => 'A pending invite already exists for this user.',
            ]);
        }

        $invitedUser = User::query()->findOrFail($invitedUserId);

        if (
            $socialGroup->visibility === SocialGroup::VISIBILITY_CAMPAIGN
            && $socialGroup->campaign_id !== null
            && ! $invitedUser->belongsToCampaign((int) $socialGroup->campaign_id)
            && ! $inviter->hasPermission('social_space.moderate')
        ) {
            return back()->withErrors([
                'invited_user_id' => 'Invited user must belong to the same campaign.',
            ]);
        }

        $invite = SocialGroupInvite::create([
            'social_group_id' => $socialGroup->id,
            'invited_by' => $inviter->id,
            'invited_user_id' => $invitedUserId,
            'status' => SocialGroupInvite::STATUS_PENDING,
        ]);

        activity('social_space')
            ->causedBy($inviter)
            ->performedOn($socialGroup)
            ->withProperties([
                'action' => 'invite.sent',
                'invite_id' => $invite->id,
                'invited_user_id' => $invitedUserId,
            ])
            ->log('social_space.invite_sent');

        return back()->with('flash', [
            'message' => 'Invite sent successfully.',
            'type' => 'success',
        ]);
    }

    public function acceptInvite(SocialGroupInvite $socialGroupInvite, Request $request)
    {
        if ($socialGroupInvite->invited_user_id !== $request->user()->id) {
            abort(403, 'Unauthorized invite action.');
        }

        if ($socialGroupInvite->status !== SocialGroupInvite::STATUS_PENDING) {
            return back()->withErrors([
                'invite' => 'This invite is no longer pending.',
            ]);
        }

        DB::transaction(function () use ($socialGroupInvite, $request) {
            $socialGroupInvite->update([
                'status' => SocialGroupInvite::STATUS_ACCEPTED,
                'responded_at' => now(),
            ]);

            SocialGroupMember::firstOrCreate(
                ['social_group_id' => $socialGroupInvite->social_group_id, 'user_id' => $request->user()->id],
                ['role' => SocialGroupMember::ROLE_MEMBER, 'joined_at' => now()]
            );
        });

        return redirect()->route('social-space.index', ['group_id' => $socialGroupInvite->social_group_id])
            ->with('flash', [
                'message' => 'Invite accepted.',
                'type' => 'success',
            ]);
    }

    public function declineInvite(SocialGroupInvite $socialGroupInvite, Request $request)
    {
        if ($socialGroupInvite->invited_user_id !== $request->user()->id) {
            abort(403, 'Unauthorized invite action.');
        }

        if ($socialGroupInvite->status !== SocialGroupInvite::STATUS_PENDING) {
            return back()->withErrors([
                'invite' => 'This invite is no longer pending.',
            ]);
        }

        $socialGroupInvite->update([
            'status' => SocialGroupInvite::STATUS_DECLINED,
            'responded_at' => now(),
        ]);

        return back()->with('flash', [
            'message' => 'Invite declined.',
            'type' => 'success',
        ]);
    }

    public function revokeInvite(SocialGroup $socialGroup, SocialGroupInvite $socialGroupInvite)
    {
        $this->authorize('manageInvites', $socialGroup);

        if ($socialGroupInvite->social_group_id !== $socialGroup->id) {
            abort(404);
        }

        if ($socialGroupInvite->status !== SocialGroupInvite::STATUS_PENDING) {
            return back()->withErrors([
                'invite' => 'Only pending invites can be revoked.',
            ]);
        }

        $socialGroupInvite->update([
            'status' => SocialGroupInvite::STATUS_REVOKED,
            'responded_at' => now(),
        ]);

        activity('social_space')
            ->causedBy(auth()->user())
            ->performedOn($socialGroup)
            ->withProperties([
                'action' => 'invite.revoked',
                'invite_id' => $socialGroupInvite->id,
                'invited_user_id' => $socialGroupInvite->invited_user_id,
            ])
            ->log('social_space.invite_revoked');

        return back()->with('flash', [
            'message' => 'Invite revoked.',
            'type' => 'success',
        ]);
    }

    public function removeMember(SocialGroup $socialGroup, SocialGroupMember $socialGroupMember, Request $request)
    {
        $this->authorize('manageMembers', $socialGroup);

        if ($socialGroupMember->social_group_id !== $socialGroup->id) {
            abort(404);
        }

        if ($socialGroupMember->role === SocialGroupMember::ROLE_OWNER) {
            return back()->withErrors([
                'member' => 'Cannot remove the current owner. Transfer ownership first.',
            ]);
        }

        if ($socialGroupMember->user_id === $request->user()->id) {
            return back()->withErrors([
                'member' => 'Use leave action for your own membership.',
            ]);
        }

        $removedUserId = $socialGroupMember->user_id;
        $socialGroupMember->delete();

        activity('social_space')
            ->causedBy($request->user())
            ->performedOn($socialGroup)
            ->withProperties([
                'action' => 'member.removed',
                'removed_user_id' => $removedUserId,
            ])
            ->log('social_space.member_removed');

        return back()->with('flash', [
            'message' => 'Member removed from group.',
            'type' => 'success',
        ]);
    }

    public function updateMemberRole(SocialGroup $socialGroup, SocialGroupMember $socialGroupMember, Request $request)
    {
        $this->authorize('manageMembers', $socialGroup);

        if ($socialGroupMember->social_group_id !== $socialGroup->id) {
            abort(404);
        }

        if ($socialGroupMember->role === SocialGroupMember::ROLE_OWNER) {
            return back()->withErrors([
                'member' => 'Owner role cannot be changed from this action.',
            ]);
        }

        $validated = $request->validate([
            'role' => ['required', 'in:admin,member'],
        ]);

        $newRole = $validated['role'];

        if ($socialGroupMember->role === $newRole) {
            return back();
        }

        $socialGroupMember->update([
            'role' => $newRole,
        ]);

        activity('social_space')
            ->causedBy($request->user())
            ->performedOn($socialGroup)
            ->withProperties([
                'action' => 'member.role_updated',
                'member_id' => $socialGroupMember->id,
                'user_id' => $socialGroupMember->user_id,
                'role' => $newRole,
            ])
            ->log('social_space.member_role_updated');

        return back()->with('flash', [
            'message' => 'Member role updated.',
            'type' => 'success',
        ]);
    }

    public function transferOwnership(SocialGroup $socialGroup, SocialGroupMember $socialGroupMember, Request $request)
    {
        $this->authorize('transferOwnership', $socialGroup);

        if ($socialGroupMember->social_group_id !== $socialGroup->id) {
            abort(404);
        }

        if ($socialGroupMember->role === SocialGroupMember::ROLE_OWNER) {
            return back();
        }

        DB::transaction(function () use ($socialGroup, $socialGroupMember) {
            $currentOwner = $socialGroup->memberRecords()
                ->where('role', SocialGroupMember::ROLE_OWNER)
                ->first();

            if (! $currentOwner) {
                return;
            }

            if ($currentOwner->id !== $socialGroupMember->id) {
                $currentOwner->update(['role' => SocialGroupMember::ROLE_ADMIN]);
            }

            $socialGroupMember->update(['role' => SocialGroupMember::ROLE_OWNER]);
        });

        activity('social_space')
            ->causedBy($request->user())
            ->performedOn($socialGroup)
            ->withProperties([
                'action' => 'ownership.transferred',
                'new_owner_user_id' => $socialGroupMember->user_id,
            ])
            ->log('social_space.ownership_transferred');

        return back()->with('flash', [
            'message' => 'Ownership transferred successfully.',
            'type' => 'success',
        ]);
    }

    public function storeGroup(StoreSocialGroupRequest $request)
    {
        $this->authorize('create', SocialGroup::class);

        $validated = $request->validated();
        $user = $request->user();

        if (
            $validated['visibility'] === SocialGroup::VISIBILITY_CAMPAIGN
            && isset($validated['campaign_id'])
            && ! $user->belongsToCampaign((int) $validated['campaign_id'])
            && ! $user->hasPermission('social_space.moderate')
        ) {
            return back()->withErrors([
                'campaign_id' => 'You can only create campaign groups for campaigns you belong to.',
            ]);
        }

        DB::transaction(function () use ($validated, $user) {
            $group = SocialGroup::create([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'visibility' => $validated['visibility'],
                'campaign_id' => $validated['campaign_id'] ?? null,
                'created_by' => $user->id,
            ]);

            SocialGroupMember::create([
                'social_group_id' => $group->id,
                'user_id' => $user->id,
                'role' => SocialGroupMember::ROLE_OWNER,
                'joined_at' => now(),
            ]);
        });

        return back()->with('flash', [
            'message' => 'Group created successfully.',
            'type' => 'success',
        ]);
    }

    public function join(SocialGroup $socialGroup, Request $request)
    {
        $this->authorize('view', $socialGroup);

        if ($socialGroup->visibility === SocialGroup::VISIBILITY_PRIVATE && ! $request->user()->hasPermission('social_space.moderate')) {
            abort(403, 'Private groups require invitation.');
        }

        SocialGroupMember::firstOrCreate(
            ['social_group_id' => $socialGroup->id, 'user_id' => $request->user()->id],
            ['role' => SocialGroupMember::ROLE_MEMBER, 'joined_at' => now()]
        );

        return back()->with('flash', [
            'message' => 'Joined group successfully.',
            'type' => 'success',
        ]);
    }

    public function leave(SocialGroup $socialGroup, Request $request)
    {
        $membership = SocialGroupMember::query()
            ->where('social_group_id', $socialGroup->id)
            ->where('user_id', $request->user()->id)
            ->first();

        if (! $membership) {
            return back();
        }

        if ($membership->role === SocialGroupMember::ROLE_OWNER) {
            return back()->withErrors([
                'group' => 'Group owners cannot leave without transferring ownership.',
            ]);
        }

        $membership->delete();

        return back()->with('flash', [
            'message' => 'Left group successfully.',
            'type' => 'success',
        ]);
    }

    public function storeMessage(SocialGroup $socialGroup, StoreSocialMessageRequest $request)
    {
        $this->authorize('sendMessage', $socialGroup);

        $membership = SocialGroupMember::query()
            ->where('social_group_id', $socialGroup->id)
            ->where('user_id', $request->user()->id)
            ->first();

        if (! $membership && $socialGroup->visibility !== SocialGroup::VISIBILITY_PRIVATE) {
            $membership = SocialGroupMember::create([
                'social_group_id' => $socialGroup->id,
                'user_id' => $request->user()->id,
                'role' => SocialGroupMember::ROLE_MEMBER,
                'joined_at' => now(),
            ]);
        }

        if (! $membership) {
            abort(403, 'You are not a member of this group.');
        }

        $validated = $request->validated();

        $parentId = null;
        if (! empty($validated['parent_id'])) {
            $parent = SocialMessage::query()
                ->where('id', $validated['parent_id'])
                ->where('social_group_id', $socialGroup->id)
                ->first();

            if (! $parent) {
                throw ValidationException::withMessages([
                    'parent_id' => 'The replied message does not belong to this conversation.',
                ]);
            }

            $parentId = $parent->id;
        }

        $message = SocialMessage::create([
            'social_group_id' => $socialGroup->id,
            'user_id' => $request->user()->id,
            'parent_id' => $parentId,
            'message' => $validated['message'] ?? '',
        ]);

        $this->storeAttachmentsForMessage($message, $request->file('attachments', []), $request->user()->id);

        try {
            broadcast(new SocialGroupMessageCreated($message))->toOthers();
        } catch (\Throwable $exception) {
            Log::warning('SocialGroupMessageCreated broadcast failed: '.$exception->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => $message->load(['user:id,first_name,last_name,avatar_url', 'attachments', 'reactions'])]);
        }

        return back()->with('flash', [
            'message' => 'Message sent.',
            'type' => 'success',
        ]);
    }

    public function storeDirectMessage(SocialDirectThread $socialDirectThread, StoreSocialMessageRequest $request)
    {
        if (! $request->user()->hasPermission('social_space.message')) {
            abort(403);
        }

        $participant = SocialDirectThreadParticipant::query()
            ->where('social_direct_thread_id', $socialDirectThread->id)
            ->where('user_id', $request->user()->id)
            ->first();

        if (! $participant) {
            abort(403);
        }

        $validated = $request->validated();

        $parentId = null;
        if (! empty($validated['parent_id'])) {
            $parent = SocialDirectMessage::query()
                ->where('id', $validated['parent_id'])
                ->where('social_direct_thread_id', $socialDirectThread->id)
                ->first();

            if (! $parent) {
                throw ValidationException::withMessages([
                    'parent_id' => 'The replied message does not belong to this conversation.',
                ]);
            }

            $parentId = $parent->id;
        }

        $message = SocialDirectMessage::create([
            'social_direct_thread_id' => $socialDirectThread->id,
            'user_id' => $request->user()->id,
            'parent_id' => $parentId,
            'message' => $validated['message'] ?? '',
        ]);

        $this->storeAttachmentsForMessage($message, $request->file('attachments', []), $request->user()->id);

        $participant->update(['last_read_at' => now()]);

        $socialDirectThread->update(['last_message_at' => now()]);

        try {
            broadcast(new SocialDirectMessageCreated($message))->toOthers();
        } catch (\Throwable $exception) {
            Log::warning('SocialDirectMessageCreated broadcast failed: '.$exception->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => $message->load(['user:id,first_name,last_name,avatar_url', 'attachments', 'reactions'])]);
        }

        return back()->with('flash', [
            'message' => 'Direct message sent.',
            'type' => 'success',
        ]);
    }

    public function updateGroupMessage(SocialGroup $socialGroup, SocialMessage $socialMessage, UpdateSocialMessageRequest $request)
    {
        $this->authorize('sendMessage', $socialGroup);

        if ($socialMessage->social_group_id !== $socialGroup->id) {
            abort(404);
        }

        if ($socialMessage->user_id !== $request->user()->id) {
            abort(403);
        }

        $socialMessage->update([
            'message' => $request->validated('message'),
            'edited_at' => now(),
        ]);

        try {
            broadcast(new SocialGroupMessageUpdated($socialGroup->id, $socialMessage->id, 'edited'))->toOthers();
        } catch (\Exception $exception) {
            Log::warning('SocialGroupMessageUpdated broadcast failed: '.$exception->getMessage());
        }

        return back()->with('flash', [
            'message' => 'Message updated.',
            'type' => 'success',
        ]);
    }

    public function deleteGroupMessage(SocialGroup $socialGroup, SocialMessage $socialMessage, Request $request)
    {
        $this->authorize('sendMessage', $socialGroup);

        if ($socialMessage->social_group_id !== $socialGroup->id) {
            abort(404);
        }

        if ($socialMessage->user_id !== $request->user()->id && ! $request->user()->hasPermission('social_space.moderate')) {
            abort(403);
        }

        $messageId = $socialMessage->id;
        $socialMessage->delete();

        try {
            broadcast(new SocialGroupMessageUpdated($socialGroup->id, $messageId, 'deleted'))->toOthers();
        } catch (\Exception $exception) {
            Log::warning('SocialGroupMessageUpdated broadcast failed: '.$exception->getMessage());
        }

        return back()->with('flash', [
            'message' => 'Message deleted.',
            'type' => 'success',
        ]);
    }

    public function updateDirectMessage(SocialDirectThread $socialDirectThread, SocialDirectMessage $socialDirectMessage, UpdateSocialMessageRequest $request)
    {
        $participant = SocialDirectThreadParticipant::query()
            ->where('social_direct_thread_id', $socialDirectThread->id)
            ->where('user_id', $request->user()->id)
            ->first();

        if (! $participant || $socialDirectMessage->social_direct_thread_id !== $socialDirectThread->id) {
            abort(403);
        }

        if ($socialDirectMessage->user_id !== $request->user()->id) {
            abort(403);
        }

        $socialDirectMessage->update([
            'message' => $request->validated('message'),
            'edited_at' => now(),
        ]);

        try {
            broadcast(new SocialDirectMessageUpdated($socialDirectThread->id, $socialDirectMessage->id, 'edited'))->toOthers();
        } catch (\Exception $exception) {
            Log::warning('SocialDirectMessageUpdated broadcast failed: '.$exception->getMessage());
        }

        return back()->with('flash', [
            'message' => 'Direct message updated.',
            'type' => 'success',
        ]);
    }

    public function deleteDirectMessage(SocialDirectThread $socialDirectThread, SocialDirectMessage $socialDirectMessage, Request $request)
    {
        $participant = SocialDirectThreadParticipant::query()
            ->where('social_direct_thread_id', $socialDirectThread->id)
            ->where('user_id', $request->user()->id)
            ->first();

        if (! $participant || $socialDirectMessage->social_direct_thread_id !== $socialDirectThread->id) {
            abort(403);
        }

        if ($socialDirectMessage->user_id !== $request->user()->id && ! $request->user()->hasPermission('social_space.moderate')) {
            abort(403);
        }

        $messageId = $socialDirectMessage->id;
        $socialDirectMessage->delete();

        try {
            broadcast(new SocialDirectMessageUpdated($socialDirectThread->id, $messageId, 'deleted'))->toOthers();
        } catch (\Exception $exception) {
            Log::warning('SocialDirectMessageUpdated broadcast failed: '.$exception->getMessage());
        }

        return back()->with('flash', [
            'message' => 'Direct message deleted.',
            'type' => 'success',
        ]);
    }

    public function markDirectThreadRead(SocialDirectThread $socialDirectThread, Request $request)
    {
        $participant = SocialDirectThreadParticipant::query()
            ->where('social_direct_thread_id', $socialDirectThread->id)
            ->where('user_id', $request->user()->id)
            ->first();

        if (! $participant) {
            abort(403);
        }

        $participant->update(['last_read_at' => now()]);

        return response()->json(['ok' => true]);
    }

    public function updateGroupTheme(SocialGroup $socialGroup, UpdateSocialConversationThemeRequest $request)
    {
        if (! $request->user()->can('view', $socialGroup)) {
            abort(403);
        }

        $isMember = $socialGroup->memberRecords()
            ->where('user_id', $request->user()->id)
            ->exists();

        if (! $isMember && ! $request->user()->hasPermission('social_space.moderate')) {
            abort(403);
        }

        $data = [
            'theme_color' => $request->validated('theme_color'),
            'theme_background' => $request->validated('theme_background'),
        ];

        if ($request->hasFile('background_image')) {
            if ($socialGroup->theme_background_image_path) {
                Storage::disk('public')->delete($socialGroup->theme_background_image_path);
            }
            $data['theme_background_image_path'] = $request->file('background_image')->store('social/backgrounds', 'public');
        }

        $socialGroup->update($data);

        return back()->with('flash', [
            'message' => 'Group theme updated.',
            'type' => 'success',
        ]);
    }

    public function updateDirectTheme(SocialDirectThread $socialDirectThread, UpdateSocialConversationThemeRequest $request)
    {
        $isParticipant = SocialDirectThreadParticipant::query()
            ->where('social_direct_thread_id', $socialDirectThread->id)
            ->where('user_id', $request->user()->id)
            ->exists();

        if (! $isParticipant) {
            abort(403);
        }

        $data = [
            'theme_color' => $request->validated('theme_color'),
            'theme_background' => $request->validated('theme_background'),
        ];

        if ($request->hasFile('background_image')) {
            if ($socialDirectThread->theme_background_image_path) {
                Storage::disk('public')->delete($socialDirectThread->theme_background_image_path);
            }
            $data['theme_background_image_path'] = $request->file('background_image')->store('social/backgrounds', 'public');
        }

        $socialDirectThread->update($data);

        return back()->with('flash', [
            'message' => 'Conversation theme updated.',
            'type' => 'success',
        ]);
    }

    public function toggleGroupPin(SocialGroup $socialGroup, Request $request)
    {
        $member = SocialGroupMember::query()
            ->where('social_group_id', $socialGroup->id)
            ->where('user_id', $request->user()->id)
            ->first();

        if (! $member) {
            abort(403);
        }

        $member->update(['is_pinned' => ! $member->is_pinned]);

        return back()->with('flash', [
            'message' => $member->is_pinned ? 'Conversation pinned.' : 'Conversation unpinned.',
            'type' => 'success',
        ]);
    }

    public function toggleDirectPin(SocialDirectThread $socialDirectThread, Request $request)
    {
        $participant = SocialDirectThreadParticipant::query()
            ->where('social_direct_thread_id', $socialDirectThread->id)
            ->where('user_id', $request->user()->id)
            ->first();

        if (! $participant) {
            abort(403);
        }

        $participant->update(['is_pinned' => ! $participant->is_pinned]);

        return back()->with('flash', [
            'message' => $participant->is_pinned ? 'Conversation pinned.' : 'Conversation unpinned.',
            'type' => 'success',
        ]);
    }

    public function updateDirectThreadName(SocialDirectThread $socialDirectThread, Request $request)
    {
        $request->validate([
            'name' => ['required', 'string', 'max:100'],
        ]);

        $isParticipant = SocialDirectThreadParticipant::query()
            ->where('social_direct_thread_id', $socialDirectThread->id)
            ->where('user_id', $request->user()->id)
            ->exists();

        if (! $isParticipant || ! $socialDirectThread->is_group) {
            abort(403);
        }

        $socialDirectThread->update(['name' => $request->input('name')]);

        return back()->with('flash', [
            'message' => 'Group name updated.',
            'type' => 'success',
        ]);
    }

    public function updateDirectThreadImage(SocialDirectThread $socialDirectThread, Request $request)
    {
        $request->validate([
            'image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $isParticipant = SocialDirectThreadParticipant::query()
            ->where('social_direct_thread_id', $socialDirectThread->id)
            ->where('user_id', $request->user()->id)
            ->exists();

        if (! $isParticipant || ! $socialDirectThread->is_group) {
            abort(403);
        }

        if ($socialDirectThread->image_path) {
            Storage::disk('public')->delete($socialDirectThread->image_path);
        }

        $path = $request->file('image')->store('social/group-images', 'public');
        $socialDirectThread->update(['image_path' => $path]);

        return back()->with('flash', [
            'message' => 'Group photo updated.',
            'type' => 'success',
        ]);
    }

    public function toggleGroupMessageReaction(SocialGroup $socialGroup, SocialMessage $socialMessage, ToggleSocialReactionRequest $request)
    {
        $this->authorize('sendMessage', $socialGroup);

        if ($socialMessage->social_group_id !== $socialGroup->id) {
            abort(404);
        }

        $result = $this->toggleReaction(
            $socialMessage,
            $request->user()->id,
            $request->validated('emoji')
        );

        try {
            broadcast(new SocialGroupMessageUpdated($socialGroup->id, $socialMessage->id, 'reaction'))->toOthers();
        } catch (\Exception $exception) {
            Log::warning('SocialGroupMessageUpdated broadcast failed: '.$exception->getMessage());
        }

        return $result;
    }

    public function toggleDirectMessageReaction(SocialDirectThread $socialDirectThread, SocialDirectMessage $socialDirectMessage, ToggleSocialReactionRequest $request)
    {
        $participant = SocialDirectThreadParticipant::query()
            ->where('social_direct_thread_id', $socialDirectThread->id)
            ->where('user_id', $request->user()->id)
            ->first();

        if (! $participant || $socialDirectMessage->social_direct_thread_id !== $socialDirectThread->id) {
            abort(403);
        }

        $result = $this->toggleReaction(
            $socialDirectMessage,
            $request->user()->id,
            $request->validated('emoji')
        );

        try {
            broadcast(new SocialDirectMessageUpdated($socialDirectThread->id, $socialDirectMessage->id, 'reaction'))->toOthers();
        } catch (\Exception $exception) {
            Log::warning('SocialDirectMessageUpdated broadcast failed: '.$exception->getMessage());
        }

        return $result;
    }

    private function toggleReaction(SocialMessage|SocialDirectMessage $message, int $userId, string $emoji)
    {
        $reaction = SocialReaction::query()
            ->where('reactable_type', $message::class)
            ->where('reactable_id', $message->id)
            ->where('user_id', $userId)
            ->where('emoji', $emoji)
            ->first();

        if ($reaction) {
            $reaction->delete();
        } else {
            SocialReaction::create([
                'reactable_type' => $message::class,
                'reactable_id' => $message->id,
                'user_id' => $userId,
                'emoji' => $emoji,
            ]);
        }

        return response()->json([
            'ok' => true,
            'reactions' => $message->reactions()->with('user:id,first_name,last_name')->get(),
        ]);
    }

    private function storeAttachmentsForMessage(SocialMessage|SocialDirectMessage $message, array $uploadedFiles, int $userId): void
    {
        if (empty($uploadedFiles)) {
            return;
        }

        foreach ($uploadedFiles as $uploadedFile) {
            if (! $uploadedFile instanceof UploadedFile) {
                continue;
            }

            $storedPath = $uploadedFile->store('social-attachments', 'local');

            SocialAttachment::create([
                'user_id' => $userId,
                'attachable_type' => $message::class,
                'attachable_id' => $message->id,
                'disk' => 'local',
                'path' => $storedPath,
                'original_name' => $uploadedFile->getClientOriginalName(),
                'mime' => $uploadedFile->getClientMimeType() ?? 'application/octet-stream',
                'size' => $uploadedFile->getSize(),
            ]);
        }
    }

    public function downloadAttachment(SocialAttachment $socialAttachment, Request $request)
    {
        $attachable = $socialAttachment->attachable;

        if ($attachable instanceof SocialMessage) {
            $group = $attachable->group;
            if (! $group || ! $request->user()->can('view', $group)) {
                abort(403);
            }
        }

        if ($attachable instanceof SocialDirectMessage) {
            $isParticipant = SocialDirectThreadParticipant::query()
                ->where('social_direct_thread_id', $attachable->social_direct_thread_id)
                ->where('user_id', $request->user()->id)
                ->exists();

            if (! $isParticipant) {
                abort(403);
            }
        }

        if (! Storage::disk($socialAttachment->disk)->exists($socialAttachment->path)) {
            abort(404);
        }

        return Storage::disk($socialAttachment->disk)->download($socialAttachment->path, $socialAttachment->original_name);
    }
}
