<?php

namespace App\Http\Controllers;

use App\Http\Traits\RedirectsWithFlashMessages;
use App\Models\Campaign;
use App\Models\CoachingExclusion;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class CoachingExclusionsController extends Controller
{
    use RedirectsWithFlashMessages;

    public function index(Request $request): Response
    {
        abort_unless($request->user()?->hasPermission('coaching.manage_exclusions'), 403);

        $search = trim((string) $request->input('search', ''));
        $role = $request->input('role');
        $campaignId = $request->input('campaign_id');
        $status = $request->input('status', 'all'); // all|excluded|included
        $perPage = (int) $request->input('per_page', 15);

        $usersQuery = User::query()
            ->whereIn('role', ['Agent', 'Team Lead'])
            ->where('is_active', true)
            ->where('is_approved', true)
            ->with([
                'activeSchedule.campaign:id,name',
                'activeCoachingExclusion.excludedBy:id,first_name,last_name',
            ]);

        if ($search !== '') {
            $usersQuery->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$search}%"]);
            });
        }

        if ($role) {
            $usersQuery->where('role', $role);
        }

        if ($campaignId) {
            $usersQuery->whereHas('activeSchedule', fn ($q) => $q->where('campaign_id', $campaignId));
        }

        if ($status === 'excluded') {
            $usersQuery->coachingExcluded();
        } elseif ($status === 'included') {
            $usersQuery->notCoachingExcluded();
        }

        $users = $usersQuery
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->paginate($perPage)
            ->withQueryString();

        $users->getCollection()->transform(function (User $user) {
            $exclusion = $user->activeCoachingExclusion;

            return [
                'id' => $user->id,
                'name' => trim($user->first_name.' '.$user->last_name),
                'email' => $user->email,
                'role' => $user->role,
                'campaign' => $user->activeSchedule?->campaign?->name,
                'is_excluded' => $exclusion !== null,
                'exclusion' => $exclusion ? [
                    'id' => $exclusion->id,
                    'reason' => $exclusion->reason,
                    'notes' => $exclusion->notes,
                    'excluded_at' => $exclusion->excluded_at?->toIso8601String(),
                    'expires_at' => $exclusion->expires_at?->toIso8601String(),
                    'excluded_by' => $exclusion->excludedBy
                        ? trim($exclusion->excludedBy->first_name.' '.$exclusion->excludedBy->last_name)
                        : null,
                ] : null,
            ];
        });

        $allUsers = User::query()
            ->whereIn('role', ['Agent', 'Team Lead'])
            ->where('is_active', true)
            ->where('is_approved', true)
            ->with(['activeSchedule.campaign:id,name'])
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'email', 'role'])
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => trim($u->first_name.' '.$u->last_name),
                'email' => $u->email,
                'role' => $u->role,
                'campaign' => $u->activeSchedule?->campaign?->name,
            ]);

        return Inertia::render('Coaching/Exclusions/Index', [
            'users' => $users,
            'allUsers' => $allUsers,
            'filters' => [
                'search' => $search,
                'role' => $role,
                'campaign_id' => $campaignId,
                'status' => $status,
                'per_page' => $perPage,
            ],
            'campaigns' => Campaign::orderBy('name')->get(['id', 'name']),
            'reasons' => CoachingExclusion::REASONS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->hasPermission('coaching.manage_exclusions'), 403);

        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'reason' => ['required', 'string', 'in:'.implode(',', CoachingExclusion::REASONS)],
            'notes' => ['nullable', 'string', 'max:2000'],
            'excluded_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after:excluded_at'],
        ]);

        try {
            DB::transaction(function () use ($data, $request) {
                $user = User::findOrFail($data['user_id']);

                if ($user->isCoachingExcluded()) {
                    return;
                }

                CoachingExclusion::create([
                    'user_id' => $user->id,
                    'reason' => $data['reason'],
                    'notes' => $data['notes'] ?? null,
                    'excluded_at' => $data['excluded_at'] ?? now(),
                    'expires_at' => $data['expires_at'] ?? null,
                    'excluded_by' => $request->user()->id,
                ]);
            });

            return $this->backWithFlash('User excluded from coaching.');
        } catch (\Throwable $e) {
            Log::error('CoachingExclusions store failed: '.$e->getMessage());

            return $this->backWithFlash('Failed to exclude user from coaching.', 'error');
        }
    }

    public function bulkStore(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->hasPermission('coaching.manage_exclusions'), 403);

        $data = $request->validate([
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['integer', 'exists:users,id'],
            'reason' => ['required', 'string', 'in:'.implode(',', CoachingExclusion::REASONS)],
            'notes' => ['nullable', 'string', 'max:2000'],
            'excluded_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after:excluded_at'],
        ]);

        try {
            $count = 0;
            DB::transaction(function () use ($data, $request, &$count) {
                $users = User::whereIn('id', $data['user_ids'])->get();
                foreach ($users as $user) {
                    if ($user->isCoachingExcluded()) {
                        continue;
                    }
                    CoachingExclusion::create([
                        'user_id' => $user->id,
                        'reason' => $data['reason'],
                        'notes' => $data['notes'] ?? null,
                        'excluded_at' => $data['excluded_at'] ?? now(),
                        'expires_at' => $data['expires_at'] ?? null,
                        'excluded_by' => $request->user()->id,
                    ]);
                    $count++;
                }
            });

            return $this->backWithFlash("{$count} user(s) excluded from coaching.");
        } catch (\Throwable $e) {
            Log::error('CoachingExclusions bulkStore failed: '.$e->getMessage());

            return $this->backWithFlash('Failed to bulk-exclude users.', 'error');
        }
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()?->hasPermission('coaching.manage_exclusions'), 403);

        $data = $request->validate([
            'revoke_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            DB::transaction(function () use ($user, $data, $request) {
                $exclusion = $user->coachingExclusions()
                    ->whereNull('revoked_at')
                    ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                    ->first();

                if (! $exclusion) {
                    return;
                }

                $exclusion->update([
                    'revoked_at' => now(),
                    'revoked_by' => $request->user()->id,
                    'revoke_notes' => $data['revoke_notes'] ?? null,
                ]);
            });

            return $this->backWithFlash('User restored to coaching eligibility.');
        } catch (\Throwable $e) {
            Log::error('CoachingExclusions destroy failed: '.$e->getMessage());

            return $this->backWithFlash('Failed to restore user.', 'error');
        }
    }

    public function history(User $user): Response
    {
        abort_unless(request()->user()?->hasPermission('coaching.manage_exclusions'), 403);

        $history = $user->coachingExclusions()
            ->with(['excludedBy:id,first_name,last_name', 'revokedBy:id,first_name,last_name'])
            ->get()
            ->map(fn (CoachingExclusion $e) => [
                'id' => $e->id,
                'reason' => $e->reason,
                'notes' => $e->notes,
                'excluded_at' => $e->excluded_at?->toIso8601String(),
                'expires_at' => $e->expires_at?->toIso8601String(),
                'revoked_at' => $e->revoked_at?->toIso8601String(),
                'revoke_notes' => $e->revoke_notes,
                'excluded_by' => $e->excludedBy
                    ? trim($e->excludedBy->first_name.' '.$e->excludedBy->last_name)
                    : null,
                'revoked_by' => $e->revokedBy
                    ? trim($e->revokedBy->first_name.' '.$e->revokedBy->last_name)
                    : null,
                'is_active' => $e->isActive(),
            ]);

        return Inertia::render('Coaching/Exclusions/History', [
            'user' => [
                'id' => $user->id,
                'name' => trim($user->first_name.' '.$user->last_name),
                'role' => $user->role,
            ],
            'history' => $history,
        ]);
    }
}
