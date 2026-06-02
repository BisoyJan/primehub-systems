<?php

namespace App\Services;

use App\Models\BreakEvent;
use App\Models\BreakPolicy;
use App\Models\BreakSession;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class BreakTimerService
{
    public function __construct(protected NotificationService $notificationService) {}

    /**
     * Get the logical shift date based on the policy's shift_reset_time.
     *
     * If current time is before the reset time, the agent is still on yesterday's shift.
     * Example: reset_time=06:00, current=01:30 AM Apr 4 → shift_date = Apr 3
     * Example: reset_time=06:00, current=08:00 AM Apr 4 → shift_date = Apr 4
     */
    public function getShiftDate(?BreakPolicy $policy = null): string
    {
        $now = Carbon::now();
        $resetTime = $policy?->shift_reset_time ?? '06:00';
        $todayReset = Carbon::today()->setTimeFromTimeString($resetTime);

        if ($now->lt($todayReset)) {
            return Carbon::yesterday()->toDateString();
        }

        return $now->toDateString();
    }

    public function getActivePolicy(): ?BreakPolicy
    {
        return BreakPolicy::query()->where('is_active', true)->first();
    }

    public function getTodaySessions(int $userId, string $date): Collection
    {
        return BreakSession::query()
            ->forUser($userId)
            ->forDate($date)
            ->with('breakEvents')
            ->orderBy('started_at', 'desc')
            ->get();
    }

    /**
     * @return array{
     *     expected_end_at: ?Carbon,
     *     elapsed_seconds: int,
     *     remaining_seconds: int,
     *     overage_seconds: int,
     *     total_paused_seconds: int,
     *     is_overbreak_now: bool
     * }
     */
    public function getSessionTimingSnapshot(BreakSession $session): array
    {
        $currentPauseSeconds = $this->getCurrentPauseSeconds($session);
        $totalPaused = $session->total_paused_seconds + $currentPauseSeconds;

        if ($session->status === 'active') {
            $elapsed = max(0, (int) now()->diffInSeconds($session->started_at, absolute: true) - $totalPaused);
        } elseif (in_array($session->status, ['completed', 'overage', 'auto_ended', 'reset'], true)) {
            $elapsed = max(
                0,
                ($session->duration_seconds - max(0, (int) $session->remaining_seconds)) + max(0, (int) $session->overage_seconds),
            );
        } else {
            $elapsed = max(0, $session->duration_seconds - max(0, (int) $session->remaining_seconds));
        }

        $remaining = max(0, $session->duration_seconds - $elapsed);
        $overage = max(0, $elapsed - $session->duration_seconds);

        return [
            'expected_end_at' => $session->started_at?->copy()->addSeconds($session->duration_seconds + $totalPaused),
            'elapsed_seconds' => $elapsed,
            'remaining_seconds' => $remaining,
            'overage_seconds' => $overage,
            'total_paused_seconds' => $totalPaused,
            'is_overbreak_now' => $session->status === 'active' && $overage > 0,
        ];
    }

    public function notifyAdminsAboutActiveOverbreaks(): int
    {
        $notifiedSessions = 0;

        $sessions = BreakSession::query()
            ->where('status', 'active')
            ->whereNull('overbreak_notified_at')
            ->with(['breakEvents', 'user'])
            ->get();

        $overbreakRows = [];

        foreach ($sessions as $session) {
            $timing = $this->getSessionTimingSnapshot($session);

            if (! $timing['is_overbreak_now']) {
                continue;
            }

            $overbreakRows[] = [
                'session' => $session,
                'overage' => $timing['overage_seconds'],
            ];
        }

        if (empty($overbreakRows)) {
            return 0;
        }

        // Batch threshold: when many agents are simultaneously in overbreak,
        // collapse the per-agent admin pings into a single digest notification.
        // Each session is still individually marked as notified to prevent
        // re-firing on the next cron tick.
        $batchThreshold = 5;

        if (count($overbreakRows) >= $batchThreshold) {
            $payload = array_map(function (array $row) {
                /** @var BreakSession $session */
                $session = $row['session'];
                $user = $session->user;
                $name = $user ? trim($user->first_name.' '.$user->last_name) : 'Unknown';

                return [
                    'user_id' => $session->user_id,
                    'agent_name' => $name,
                    'break_type' => $session->type,
                    'overage_seconds' => $row['overage'],
                    'date' => $session->shift_date,
                ];
            }, $overbreakRows);

            $this->notificationService->notifyBreakOverageDigestToAdmins($payload);

            foreach ($overbreakRows as $row) {
                $row['session']->forceFill(['overbreak_notified_at' => now()])->save();
                $notifiedSessions++;
            }

            return $notifiedSessions;
        }

        // Below threshold: individual notifications.
        foreach ($overbreakRows as $row) {
            /** @var BreakSession $session */
            $session = $row['session'];

            $this->notificationService->notifyBreakOverageToAdmins(
                $session->user_id,
                $session->type,
                $row['overage'],
                $session->shift_date,
            );

            $session->forceFill([
                'overbreak_notified_at' => now(),
            ])->save();

            $notifiedSessions++;
        }

        return $notifiedSessions;
    }

    public function getActiveSession(Collection $todaySessions): ?BreakSession
    {
        return $todaySessions->whereIn('status', ['active', 'paused'])->first();
    }

    /**
     * Build the pause reason note shown to the agent when their session is
     * restored. Includes the restoring user's role label and name when the
     * role is Team Lead or an admin variant.
     */
    private static function buildRestorePauseReason(string $adminName, ?string $adminRole): string
    {
        $roleLabel = match ($adminRole) {
            'Team Lead' => 'Team Lead',
            'super_admin' => 'Super Admin',
            'admin' => 'Admin',
            default => null,
        };

        if ($roleLabel !== null) {
            return "{$roleLabel} {$adminName} restored this session – press Resume to continue your break.";
        }

        return 'Restored by admin – press Resume to continue your break.';
    }

    public function getBreaksUsed(Collection $todaySessions): int
    {
        $count = 0;
        foreach ($todaySessions as $session) {
            if (! in_array($session->status, ['completed', 'overage', 'active', 'paused', 'auto_ended'])) {
                continue;
            }
            if (in_array($session->type, ['1st_break', '2nd_break', 'break'])) {
                $count += 1;
            } elseif (in_array($session->type, ['combined', 'combined_break'])) {
                $count += $session->combined_break_count ?? 1;
            }
        }

        return $count;
    }

    public function isLunchUsed(Collection $todaySessions): bool
    {
        return $todaySessions->whereIn('type', ['lunch', 'combined'])
            ->whereIn('status', ['completed', 'overage', 'active', 'paused', 'auto_ended'])
            ->count() > 0;
    }

    /**
     * @return array{duration_seconds: int, type: string, combined_break_count: int|null}
     *
     * @throws \RuntimeException
     */
    public function validateAndGetDuration(
        string $type,
        int $userId,
        string $date,
        BreakPolicy $policy,
        ?int $combinedBreakCount = null,
    ): array {
        $isLunch = $type === 'lunch';
        $isCombined = $type === 'combined';
        $isCombinedBreak = $type === 'combined_break';

        if ($isCombinedBreak) {
            $requiredBreaks = $combinedBreakCount ?? 2;

            if ($requiredBreaks < 2 || $requiredBreaks > $policy->max_breaks) {
                throw new \RuntimeException("Invalid combined break count: {$requiredBreaks}. Must be between 2 and {$policy->max_breaks}.");
            }

            $breakCount = $this->getBreaksUsedRaw($userId, $date);

            if (($policy->max_breaks - $breakCount) < $requiredBreaks) {
                throw new \RuntimeException("Not enough breaks remaining. Need {$requiredBreaks}, have ".max(0, $policy->max_breaks - $breakCount).'.');
            }

            $totalMinutes = $policy->break_duration_minutes * $requiredBreaks;

            return [
                'duration_seconds' => $totalMinutes * 60,
                'type' => 'combined_break',
                'combined_break_count' => $requiredBreaks,
            ];
        }

        if ($isCombined) {
            $requiredBreaks = $combinedBreakCount ?? 1;

            if ($requiredBreaks < 1 || $requiredBreaks > $policy->max_breaks) {
                throw new \RuntimeException("Invalid combined break count: {$requiredBreaks}. Must be between 1 and {$policy->max_breaks}.");
            }

            $breakCount = $this->getBreaksUsedRaw($userId, $date);

            if (($policy->max_breaks - $breakCount) < $requiredBreaks) {
                throw new \RuntimeException("Not enough breaks remaining. Need {$requiredBreaks}, have ".max(0, $policy->max_breaks - $breakCount).'.');
            }

            $lunchCount = BreakSession::query()
                ->forUser($userId)
                ->forDate($date)
                ->where('status', '!=', 'reset')
                ->where(function ($q) {
                    $q->where('type', 'combined')
                        ->orWhere('type', 'lunch');
                })
                ->count();

            if ($lunchCount >= $policy->max_lunch) {
                throw new \RuntimeException('Lunch break already used for today.');
            }

            $breakMinutes = $policy->break_duration_minutes * $requiredBreaks;
            $totalMinutes = $breakMinutes + $policy->lunch_duration_minutes;

            return [
                'duration_seconds' => $totalMinutes * 60,
                'type' => 'combined',
                'combined_break_count' => $requiredBreaks,
            ];
        }

        if ($isLunch) {
            $lunchCount = BreakSession::query()
                ->forUser($userId)
                ->forDate($date)
                ->where('status', '!=', 'reset')
                ->whereIn('type', ['lunch', 'combined'])
                ->count();

            if ($lunchCount >= $policy->max_lunch) {
                throw new \RuntimeException('Lunch break already used for today.');
            }

            return [
                'duration_seconds' => $policy->lunch_duration_minutes * 60,
                'type' => $type,
            ];
        }

        $breakCount = $this->getBreaksUsedRaw($userId, $date);

        if ($breakCount >= $policy->max_breaks) {
            throw new \RuntimeException('No breaks remaining for today.');
        }

        // Use ordinal names for first 2 breaks, generic 'break' for additional ones
        if ($breakCount === 0) {
            $breakType = '1st_break';
        } elseif ($breakCount === 1) {
            $breakType = '2nd_break';
        } else {
            $breakType = 'break';
        }

        return [
            'duration_seconds' => $policy->break_duration_minutes * 60,
            'type' => $breakType,
            'combined_break_count' => null,
        ];
    }

    protected function getBreaksUsedRaw(int $userId, string $date): int
    {
        $sessions = BreakSession::query()
            ->forUser($userId)
            ->forDate($date)
            ->where('status', '!=', 'reset')
            ->get();

        $count = 0;
        foreach ($sessions as $session) {
            if (in_array($session->type, ['1st_break', '2nd_break', 'break'])) {
                $count += 1;
            } elseif (in_array($session->type, ['combined', 'combined_break'])) {
                $count += $session->combined_break_count ?? 1;
            }
        }

        return $count;
    }

    public function startSession(
        int $userId,
        string $type,
        int $durationSeconds,
        int $policyId,
        ?string $station,
        string $date,
        ?int $combinedBreakCount = null,
    ): BreakSession {
        return DB::transaction(function () use ($userId, $type, $durationSeconds, $policyId, $station, $date, $combinedBreakCount) {
            $prefix = strtoupper(str_replace('_', '', $type));
            $sessionId = "{$prefix}-{$userId}-".now()->timestamp.'-'.rand(1000, 9999);

            $session = BreakSession::create([
                'session_id' => $sessionId,
                'user_id' => $userId,
                'station' => $station,
                'break_policy_id' => $policyId,
                'type' => $type,
                'status' => 'active',
                'duration_seconds' => $durationSeconds,
                'started_at' => now(),
                'remaining_seconds' => $durationSeconds,
                'overage_seconds' => 0,
                'total_paused_seconds' => 0,
                'shift_date' => $date,
                'combined_break_count' => $combinedBreakCount,
            ]);

            BreakEvent::create([
                'break_session_id' => $session->id,
                'action' => 'start',
                'remaining_seconds' => $durationSeconds,
                'overage_seconds' => 0,
                'occurred_at' => now(),
            ]);

            return $session;
        });
    }

    public function pauseSession(BreakSession $session, string $reason): void
    {
        DB::transaction(function () use ($session, $reason) {
            $locked = BreakSession::query()->whereKey($session->id)->lockForUpdate()->first();

            if (! $locked || $locked->status !== 'active') {
                return;
            }

            $session = $locked;

            $elapsed = (int) now()->diffInSeconds($session->started_at, absolute: true) - $session->total_paused_seconds;
            $remaining = max(0, $session->duration_seconds - $elapsed);

            $session->update([
                'status' => 'paused',
                'remaining_seconds' => $remaining,
                'last_pause_reason' => $reason,
            ]);

            BreakEvent::create([
                'break_session_id' => $session->id,
                'action' => 'pause',
                'remaining_seconds' => $remaining,
                'overage_seconds' => 0,
                'reason' => $reason,
                'occurred_at' => now(),
            ]);
        });
    }

    public function resumeSession(BreakSession $session): void
    {
        DB::transaction(function () use ($session) {
            $locked = BreakSession::query()->whereKey($session->id)->lockForUpdate()->first();

            if (! $locked || $locked->status !== 'paused') {
                return;
            }

            $session = $locked;

            $lastPauseEvent = $session->breakEvents()
                ->where('action', 'pause')
                ->latest('occurred_at')
                ->first();

            $pausedDuration = $lastPauseEvent
                ? (int) now()->diffInSeconds($lastPauseEvent->occurred_at, absolute: true)
                : 0;

            $session->update([
                'status' => 'active',
                'total_paused_seconds' => $session->total_paused_seconds + $pausedDuration,
            ]);

            BreakEvent::create([
                'break_session_id' => $session->id,
                'action' => 'resume',
                'remaining_seconds' => $session->remaining_seconds,
                'overage_seconds' => 0,
                'reason' => $session->last_pause_reason,
                'occurred_at' => now(),
            ]);
        });
    }

    public function endSession(BreakSession $session): string
    {
        $status = DB::transaction(function () use ($session): string {
            // Lock the row to prevent concurrent updates from auto-reset / admin actions.
            $locked = BreakSession::query()->whereKey($session->id)->lockForUpdate()->first();

            if (! $locked) {
                return $session->status;
            }

            // If another process already ended this session, no-op.
            if (! in_array($locked->status, ['active', 'paused'], true)) {
                $session->setRawAttributes($locked->getAttributes(), true);

                return $locked->status;
            }

            $session = $locked;

            $totalPaused = $session->total_paused_seconds;

            if ($session->status === 'paused') {
                $lastPauseEvent = $session->breakEvents()
                    ->where('action', 'pause')
                    ->latest('occurred_at')
                    ->first();

                if ($lastPauseEvent) {
                    $totalPaused += (int) now()->diffInSeconds($lastPauseEvent->occurred_at, absolute: true);
                }
            }

            $elapsed = (int) now()->diffInSeconds($session->started_at, absolute: true) - $totalPaused;
            $remaining = max(0, $session->duration_seconds - $elapsed);
            $overage = $elapsed > $session->duration_seconds
                ? $elapsed - $session->duration_seconds
                : 0;

            $status = $overage > 0 ? 'overage' : 'completed';

            $session->update([
                'status' => $status,
                'ended_by' => 'agent',
                'ended_at' => now(),
                'remaining_seconds' => $remaining,
                'overage_seconds' => $overage,
                'total_paused_seconds' => $totalPaused,
            ]);

            BreakEvent::create([
                'break_session_id' => $session->id,
                'action' => 'end',
                'remaining_seconds' => $remaining,
                'overage_seconds' => $overage,
                'reason' => $session->last_pause_reason,
                'occurred_at' => now(),
            ]);

            return $status;
        });

        if ($status === 'overage') {
            $session->refresh();
            $this->notificationService->notifyBreakOverage(
                $session->user_id,
                $session->type,
                $session->overage_seconds,
                $session->shift_date,
            );

            if (! $session->overbreak_notified_at) {
                $this->notificationService->notifyBreakOverageToAdmins(
                    $session->user_id,
                    $session->type,
                    $session->overage_seconds,
                    $session->shift_date,
                );

                $session->forceFill([
                    'overbreak_notified_at' => now(),
                ])->save();
            }
        }

        return $status;
    }

    protected function getCurrentPauseSeconds(BreakSession $session): int
    {
        if ($session->status !== 'paused') {
            return 0;
        }

        $lastPauseEvent = $session->relationLoaded('breakEvents')
            ? $session->breakEvents
                ->where('action', 'pause')
                ->sortByDesc('occurred_at')
                ->first()
            : $session->breakEvents()
                ->where('action', 'pause')
                ->latest('occurred_at')
                ->first();

        if (! $lastPauseEvent?->occurred_at) {
            return 0;
        }

        return (int) now()->diffInSeconds($lastPauseEvent->occurred_at, absolute: true);
    }

    /**
     * Force-end an active or paused session on behalf of an agent (admin/HR/TL action).
     *
     * Calculates final overage as if naturally ended, marks status accordingly,
     * and records a 'force_end' audit event with the admin's identity and reason.
     */
    public function forceEndSession(BreakSession $session, int $adminId, string $adminName, string $reason): string
    {
        return DB::transaction(function () use ($session, $adminId, $adminName, $reason): string {
            $locked = BreakSession::query()->whereKey($session->id)->lockForUpdate()->first();

            if (! $locked || ! in_array($locked->status, ['active', 'paused'], true)) {
                throw new \RuntimeException('Session is no longer active or paused.');
            }

            $session = $locked;

            $totalPaused = $session->total_paused_seconds;

            if ($session->status === 'paused') {
                $lastPauseEvent = $session->breakEvents()
                    ->where('action', 'pause')
                    ->latest('occurred_at')
                    ->first();

                if ($lastPauseEvent) {
                    $totalPaused += (int) now()->diffInSeconds($lastPauseEvent->occurred_at, absolute: true);
                }
            }

            $elapsed = (int) now()->diffInSeconds($session->started_at, absolute: true) - $totalPaused;
            $remaining = max(0, $session->duration_seconds - $elapsed);
            $overage = $elapsed > $session->duration_seconds
                ? $elapsed - $session->duration_seconds
                : 0;

            $status = $overage > 0 ? 'overage' : 'completed';

            $session->update([
                'status' => $status,
                'ended_by' => 'admin',
                'ended_at' => now(),
                'remaining_seconds' => $remaining,
                'overage_seconds' => $overage,
                'total_paused_seconds' => $totalPaused,
            ]);

            BreakEvent::create([
                'break_session_id' => $session->id,
                'action' => 'force_end',
                'remaining_seconds' => $remaining,
                'overage_seconds' => $overage,
                'reason' => "Force-ended by {$adminName} (#{$adminId}): {$reason}",
                'occurred_at' => now(),
            ]);

            activity()
                ->causedBy($adminId)
                ->performedOn($session)
                ->withProperties([
                    'reason' => $reason,
                    'session_id' => $session->session_id,
                    'agent_user_id' => $session->user_id,
                    'final_status' => $status,
                    'overage_seconds' => $overage,
                ])
                ->log('Break session force-ended by admin');

            return $status;
        });
    }

    /**
     * Restore a previously ended session (completed/overage/reset/auto_ended).
     *
     * Revives the session in place by setting status='active', clearing ended_at,
     * and back-dating started_at so the agent gets back the seconds they had remaining
     * at the time of the original end. The original duration_seconds is preserved.
     */
    public function restoreSession(BreakSession $session, int $adminId, string $adminName, string $reason, ?string $adminRole = null, bool $restoreFull = false): void
    {
        DB::transaction(function () use ($session, $adminId, $adminName, $reason, $adminRole, $restoreFull) {
            $locked = BreakSession::query()->whereKey($session->id)->lockForUpdate()->first();

            if (! $locked || ! in_array($locked->status, ['completed', 'overage', 'reset', 'auto_ended'], true)) {
                throw new \RuntimeException('Session is no longer in a restorable state.');
            }

            $session = $locked;

            $duration = (int) $session->duration_seconds;
            $remaining = $restoreFull ? $duration : max(0, (int) $session->remaining_seconds);
            $consumed = max(0, $duration - $remaining);

            // Back-date started_at so (now - started_at) == consumed seconds
            // and remaining = duration - elapsed = $remaining.
            // Session is restored in 'paused' state so the agent must manually
            // resume it — prevents the timer from auto-counting on their screen.
            $session->update([
                'status' => 'paused',
                'ended_by' => null,
                'ended_at' => null,
                'started_at' => now()->subSeconds($consumed),
                'remaining_seconds' => $remaining,
                'overage_seconds' => 0,
                'total_paused_seconds' => 0,
                'last_pause_reason' => self::buildRestorePauseReason($adminName, $adminRole),
                'overbreak_notified_at' => null,
            ]);

            BreakEvent::create([
                'break_session_id' => $session->id,
                'action' => 'restore',
                'remaining_seconds' => $remaining,
                'overage_seconds' => 0,
                'reason' => "Restored by {$adminName} (#{$adminId}): {$reason}",
                'occurred_at' => now(),
            ]);

            // A pause event is required so resumeSession() can compute the
            // correct paused duration when the agent presses Resume.
            BreakEvent::create([
                'break_session_id' => $session->id,
                'action' => 'pause',
                'remaining_seconds' => $remaining,
                'overage_seconds' => 0,
                'reason' => "Auto-paused on restore by {$adminName} (#{$adminId})",
                'occurred_at' => now(),
            ]);

            activity()
                ->causedBy($adminId)
                ->performedOn($session)
                ->withProperties([
                    'reason' => $reason,
                    'session_id' => $session->session_id,
                    'agent_user_id' => $session->user_id,
                    'restored_remaining_seconds' => $remaining,
                    'restore_full' => $restoreFull,
                ])
                ->log('Break session restored by admin');
        });
    }

    /**
     * Void a single session so it no longer counts toward the agent's break/lunch quota.
     *
     * Works on any non-reset status. For active/paused sessions the session is
     * terminated first (no overbreak notification — the void is intentional).
     * The status is then set to 'reset', freeing the quota slots consumed by
     * the session's type (break, lunch, or combined).
     */
    public function voidSession(BreakSession $session, int $adminId, string $adminName, string $reason): void
    {
        DB::transaction(function () use ($session, $adminId, $adminName, $reason): void {
            $locked = BreakSession::query()->whereKey($session->id)->lockForUpdate()->first();

            if (! $locked) {
                throw new \RuntimeException('Session not found.');
            }

            if ($locked->status === 'reset') {
                throw new \RuntimeException('Session is already voided.');
            }

            $session = $locked;
            $now = now();
            $updateData = [
                'status' => 'reset',
                'ended_by' => 'admin',
            ];

            // For in-flight sessions: compute final values before voiding.
            if (in_array($session->status, ['active', 'paused'], true)) {
                $totalPaused = $session->total_paused_seconds;

                if ($session->status === 'paused') {
                    $lastPauseEvent = $session->breakEvents()
                        ->where('action', 'pause')
                        ->latest('occurred_at')
                        ->first();

                    if ($lastPauseEvent) {
                        $totalPaused += (int) $now->diffInSeconds($lastPauseEvent->occurred_at, absolute: true);
                    }
                }

                $elapsed = max(0, (int) $now->diffInSeconds($session->started_at, absolute: true) - $totalPaused);
                $remaining = max(0, $session->duration_seconds - $elapsed);
                $overage = max(0, $elapsed - $session->duration_seconds);

                $updateData['ended_at'] = $now;
                $updateData['remaining_seconds'] = $remaining;
                $updateData['overage_seconds'] = $overage;
                $updateData['total_paused_seconds'] = $totalPaused;
            }

            $session->update($updateData);

            BreakEvent::create([
                'break_session_id' => $session->id,
                'action' => 'reset',
                'remaining_seconds' => $session->remaining_seconds,
                'overage_seconds' => $session->overage_seconds,
                'reason' => "Voided by {$adminName} (#{$adminId}): {$reason}",
                'occurred_at' => $now,
            ]);

            activity()
                ->causedBy($adminId)
                ->performedOn($session)
                ->withProperties([
                    'reason' => $reason,
                    'session_id' => $session->session_id,
                    'agent_user_id' => $session->user_id,
                    'voided_type' => $session->type,
                    'combined_break_count' => $session->combined_break_count,
                ])
                ->log('Break session voided by admin');
        });
    }

    /**
     * Reimburse minutes to a break session (admin/TL action).
     *
     * Adds the given minutes back to the session's allotted duration. Useful when
     * an agent forgot to pause and time was unintentionally consumed.
     *
     * - Active/paused sessions: the on-screen remaining timer immediately extends
     *   (existing client logic recomputes from duration_seconds).
     * - Ended sessions (completed/overage/auto_ended): overage_seconds is reduced
     *   by the reimbursement; remaining_seconds is increased. If a previously
     *   overage session reaches zero overage, status flips back to 'completed'
     *   and overbreak_notified_at is cleared.
     *
     * The reimbursed amount is accumulated in `reimbursed_seconds` for reporting,
     * and an immutable `BreakEvent` action='reimburse' is written for audit.
     */
    public function reimburseMinutes(
        BreakSession $session,
        int $minutes,
        int $adminId,
        string $adminName,
        string $reason,
    ): BreakSession {
        if ($minutes < 1) {
            throw new \RuntimeException('Reimbursement must be at least 1 minute.');
        }

        $addedSeconds = $minutes * 60;

        return DB::transaction(function () use ($session, $minutes, $addedSeconds, $adminId, $adminName, $reason): BreakSession {
            $locked = BreakSession::query()->whereKey($session->id)->lockForUpdate()->first();

            if (! $locked) {
                throw new \RuntimeException('Session not found.');
            }

            if ($locked->status === 'reset') {
                throw new \RuntimeException('Voided/reset sessions cannot be reimbursed.');
            }

            $session = $locked;

            // For live (active) sessions, sync remaining_seconds against live elapsed
            // so the "consumed" cap reflects what the agent has actually used.
            if ($session->status === 'active') {
                $elapsed = (int) now()->diffInSeconds($session->started_at, absolute: true) - (int) $session->total_paused_seconds;
                $liveRemaining = max(0, (int) $session->duration_seconds - $elapsed);
                if ($liveRemaining !== (int) $session->remaining_seconds) {
                    $session->remaining_seconds = $liveRemaining;
                }
            }

            $consumed = (int) $session->duration_seconds - (int) $session->remaining_seconds + (int) $session->overage_seconds;
            $alreadyReimbursed = (int) $session->reimbursed_seconds;
            $maxReimbursable = max(0, $consumed - $alreadyReimbursed);

            if ($maxReimbursable <= 0) {
                throw new \RuntimeException('No consumed minutes available to reimburse on this session.');
            }

            if ($addedSeconds > $maxReimbursable) {
                $maxMinutes = intdiv($maxReimbursable, 60);
                $maxLabel = $maxMinutes >= 1
                    ? "{$maxMinutes} minute".($maxMinutes === 1 ? '' : 's')
                    : "{$maxReimbursable} second".($maxReimbursable === 1 ? '' : 's');
                throw new \RuntimeException("Cannot reimburse more than the consumed time. Max reimbursable: {$maxLabel}.");
            }

            $newDuration = (int) $session->duration_seconds + $addedSeconds;
            $newReimbursed = (int) $session->reimbursed_seconds + $addedSeconds;

            $updates = [
                'duration_seconds' => $newDuration,
                'reimbursed_seconds' => $newReimbursed,
            ];

            $newRemaining = (int) $session->remaining_seconds;
            $newOverage = (int) $session->overage_seconds;
            $newStatus = $session->status;

            if (in_array($session->status, ['completed', 'overage', 'auto_ended'], true)) {
                // Reduce overage first; spill remainder into remaining_seconds.
                $consumeFromOverage = min($newOverage, $addedSeconds);
                $newOverage -= $consumeFromOverage;
                $spill = $addedSeconds - $consumeFromOverage;
                $newRemaining += $spill;

                $updates['overage_seconds'] = $newOverage;
                $updates['remaining_seconds'] = $newRemaining;
                $updates['overbreak_notified_at'] = null;
                $updates['ended_at'] = null;
                $updates['ended_by'] = null;
            } else {
                // active or paused — extend the remaining countdown.
                $newRemaining += $addedSeconds;
                $updates['remaining_seconds'] = $newRemaining;
            }

            // Always land in 'paused' so the agent must explicitly resume to use the time.
            $newStatus = 'paused';
            $updates['status'] = 'paused';
            $updates['last_pause_reason'] = "Admin reimbursed {$minutes} min";

            $session->update($updates);

            BreakEvent::create([
                'break_session_id' => $session->id,
                'action' => 'reimburse',
                'remaining_seconds' => $newRemaining,
                'overage_seconds' => $newOverage,
                'reason' => "Reimbursed {$minutes} min by {$adminName} (#{$adminId}): {$reason}",
                'occurred_at' => now(),
            ]);

            activity()
                ->causedBy($adminId)
                ->performedOn($session)
                ->withProperties([
                    'reason' => $reason,
                    'minutes' => $minutes,
                    'session_id' => $session->session_id,
                    'agent_user_id' => $session->user_id,
                    'new_status' => $newStatus,
                    'total_reimbursed_seconds' => $newReimbursed,
                ])
                ->log('Break session minutes reimbursed by admin');

            return $session->refresh();
        });
    }

    public function resetShift(int $userId, string $date, string $approval): int
    {
        return DB::transaction(function () use ($userId, $date, $approval): int {
            // All non-reset sessions for the day get status='reset' so they no longer
            // count toward the agent's break quota. For sessions that were still
            // in-flight (active/paused) we compute the final overage and stamp
            // ended_by='admin'. For already-closed sessions (completed/overage/
            // auto_ended) we preserve the original ended_by/ended_at so audit
            // attribution isn't clobbered.
            $sessions = BreakSession::query()
                ->forUser($userId)
                ->forDate($date)
                ->whereNotIn('status', ['reset'])
                ->lockForUpdate()
                ->get();

            if ($sessions->isEmpty()) {
                return 0;
            }

            /** @var BreakSession $session */
            foreach ($sessions as $session) {
                $isInFlight = in_array($session->status, ['active', 'paused'], true);

                if ($isInFlight) {
                    $totalPaused = $session->total_paused_seconds;

                    if ($session->status === 'paused') {
                        $lastPauseEvent = $session->breakEvents()
                            ->where('action', 'pause')
                            ->latest('occurred_at')
                            ->first();

                        if ($lastPauseEvent) {
                            $totalPaused += (int) now()->diffInSeconds($lastPauseEvent->occurred_at, absolute: true);
                        }
                    }

                    $elapsed = (int) now()->diffInSeconds($session->started_at, absolute: true) - $totalPaused;
                    $overage = max(0, $elapsed - $session->duration_seconds);
                    $remaining = max(0, $session->duration_seconds - $elapsed);

                    $session->update([
                        'status' => 'reset',
                        'ended_by' => 'admin',
                        'ended_at' => now(),
                        'remaining_seconds' => $remaining,
                        'overage_seconds' => $overage,
                        'total_paused_seconds' => $totalPaused,
                    ]);
                } else {
                    // Preserve original ended_by/ended_at attribution; only flip status
                    // so the session no longer counts toward the quota.
                    $remaining = max(0, (int) $session->remaining_seconds);
                    $overage = max(0, (int) $session->overage_seconds);

                    $session->update([
                        'status' => 'reset',
                    ]);
                }

                BreakEvent::create([
                    'break_session_id' => $session->id,
                    'action' => 'reset',
                    'remaining_seconds' => $remaining,
                    'overage_seconds' => $overage,
                    'reason' => "Shift reset — approval: {$approval}",
                    'occurred_at' => now(),
                ]);
            }

            activity()
                ->causedBy($userId)
                ->withProperties([
                    'approval' => $approval,
                    'sessions_reset' => $sessions->count(),
                    'date' => $date,
                ])
                ->log('Break shift reset');

            return $sessions->count();
        });
    }
}
