<?php

namespace App\Services;

use App\Models\BreakEvent;
use App\Models\BreakPolicy;
use App\Models\BreakSession;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class BreakTimerService
{
    public function __construct(protected NotificationService $notificationService) {}

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

    public function getActiveSession(Collection $todaySessions): ?BreakSession
    {
        return $todaySessions->whereIn('status', ['active', 'paused'])->first();
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
            } elseif ($session->type === 'combined') {
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
                ->where('type', 'combined')
                ->orWhere(function ($q) use ($userId, $date) {
                    $q->where('user_id', $userId)
                        ->where('shift_date', $date)
                        ->where('type', 'lunch');
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
            ->get();

        $count = 0;
        foreach ($sessions as $session) {
            if (in_array($session->type, ['1st_break', '2nd_break', 'break'])) {
                $count += 1;
            } elseif ($session->type === 'combined') {
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
        }

        return $status;
    }

    public function resetShift(int $userId, string $date, string $approval): int
    {
        return DB::transaction(function () use ($userId, $date, $approval): int {
            $todaySessions = BreakSession::query()
                ->forUser($userId)
                ->forDate($date)
                ->get();

            if ($todaySessions->isEmpty()) {
                return 0;
            }

            $sessionIds = $todaySessions->pluck('id');

            BreakEvent::whereIn('break_session_id', $sessionIds)->delete();
            BreakSession::whereIn('id', $sessionIds)->delete();

            activity()
                ->causedBy($userId)
                ->withProperties([
                    'approval' => $approval,
                    'sessions_cleared' => $todaySessions->count(),
                    'date' => $date,
                ])
                ->log('Break shift reset');

            return $todaySessions->count();
        });
    }
}
