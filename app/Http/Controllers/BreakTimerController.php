<?php

namespace App\Http\Controllers;

use App\Http\Requests\BreakSessionRequest;
use App\Http\Traits\RedirectsWithFlashMessages;
use App\Models\BreakSession;
use App\Services\BreakTimerService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class BreakTimerController extends Controller
{
    use RedirectsWithFlashMessages;

    public function __construct(protected BreakTimerService $breakTimerService) {}

    public function index()
    {
        $user = auth()->user();
        $policy = $this->breakTimerService->getActivePolicy();
        $today = $this->breakTimerService->getShiftDate($policy);

        $todaySessions = $this->breakTimerService->getTodaySessions($user->id, $today);
        $activeSession = $this->breakTimerService->getActiveSession($todaySessions);
        $breaksUsed = $this->breakTimerService->getBreaksUsed($todaySessions);
        $lunchUsed = $this->breakTimerService->isLunchUsed($todaySessions);

        // Compute real-time remaining_seconds for active session so the client
        // doesn't need to compare server started_at vs client Date.now() (clock skew).
        if ($activeSession && $activeSession->status === 'active') {
            $elapsed = (int) now()->diffInSeconds($activeSession->started_at, absolute: true)
                - $activeSession->total_paused_seconds;
            $activeSession->remaining_seconds = $activeSession->duration_seconds - $elapsed;
        }

        return Inertia::render('BreakTimer/Index', [
            'policy' => $policy,
            'activeSession' => $activeSession,
            'todaySessions' => $todaySessions,
            'breaksUsed' => $breaksUsed,
            'lunchUsed' => $lunchUsed,
        ]);
    }

    public function start(BreakSessionRequest $request)
    {
        $user = auth()->user();
        $validated = $request->validated();

        $policy = $this->breakTimerService->getActivePolicy();
        $today = $this->breakTimerService->getShiftDate($policy);

        if (! $policy) {
            return $this->backWithFlash('No active break policy found. Contact your administrator.', 'error');
        }

        try {
            // Serialize concurrent start attempts for the same user/shift to prevent
            // duplicate active sessions from double-submits or multi-tab clicks.
            $result = DB::transaction(function () use ($user, $today, $policy, $validated) {
                $existing = BreakSession::query()
                    ->forUser($user->id)
                    ->forDate($today)
                    ->active()
                    ->lockForUpdate()
                    ->exists();

                if ($existing) {
                    throw new \RuntimeException('You already have an active break/lunch session.');
                }

                $duration = $this->breakTimerService->validateAndGetDuration(
                    $validated['type'],
                    $user->id,
                    $today,
                    $policy,
                    $validated['combined_break_count'] ?? null,
                );

                $this->breakTimerService->startSession(
                    $user->id,
                    $duration['type'],
                    $duration['duration_seconds'],
                    $policy->id,
                    $validated['station'] ?? null,
                    $today,
                    $duration['combined_break_count'] ?? null,
                );

                return $duration;
            });

            return $this->backWithFlash(ucfirst(str_replace('_', ' ', $result['type'])).' started.');
        } catch (QueryException $e) {
            // 1062 = MySQL duplicate-key on `break_sessions_active_guard_unique`.
            // Means a parallel request beat this one to start a session.
            // NOTE: QueryException extends RuntimeException, so this must come first.
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                return $this->backWithFlash('You already have an active break/lunch session.', 'error');
            }

            Log::error('BreakTimer Start DB Error: '.$e->getMessage());

            return $this->backWithFlash('Failed to start break. Please try again.', 'error');
        } catch (\RuntimeException $e) {
            return $this->backWithFlash($e->getMessage(), 'error');
        } catch (\Exception $e) {
            Log::error('BreakTimer Start Error: '.$e->getMessage());

            return $this->backWithFlash('Failed to start break. Please try again.', 'error');
        }
    }

    public function pause(Request $request, BreakSession $breakSession)
    {
        $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        if ($breakSession->user_id !== auth()->id()) {
            abort(403);
        }

        if ($breakSession->status !== 'active') {
            return $this->backWithFlash('This session is not active.', 'error');
        }

        try {
            $this->breakTimerService->pauseSession($breakSession, $request->input('reason'));

            return $this->backWithFlash('Timer paused.');
        } catch (\Exception $e) {
            Log::error('BreakTimer Pause Error: '.$e->getMessage());

            return $this->backWithFlash('Failed to pause timer.', 'error');
        }
    }

    public function resume(BreakSession $breakSession)
    {
        if ($breakSession->user_id !== auth()->id()) {
            abort(403);
        }

        if ($breakSession->status !== 'paused') {
            return $this->backWithFlash('This session is not paused.', 'error');
        }

        try {
            $this->breakTimerService->resumeSession($breakSession);

            return $this->backWithFlash('Timer resumed.');
        } catch (\Exception $e) {
            Log::error('BreakTimer Resume Error: '.$e->getMessage());

            return $this->backWithFlash('Failed to resume timer.', 'error');
        }
    }

    public function end(BreakSession $breakSession)
    {
        if ($breakSession->user_id !== auth()->id()) {
            abort(403);
        }

        if (! in_array($breakSession->status, ['active', 'paused'])) {
            return $this->backWithFlash('This session is already ended.', 'error');
        }

        try {
            $this->breakTimerService->endSession($breakSession);

            return $this->backWithFlash('Break ended successfully.');
        } catch (\Exception $e) {
            Log::error('BreakTimer End Error: '.$e->getMessage());

            return $this->backWithFlash('Failed to end break.', 'error');
        }
    }

    public function reset(Request $request)
    {
        $request->validate([
            'approval' => ['required', 'string', 'max:500'],
        ]);

        $user = auth()->user();
        $policy = $this->breakTimerService->getActivePolicy();
        $today = $this->breakTimerService->getShiftDate($policy);

        try {
            $this->breakTimerService->resetShift($user->id, $today, $request->input('approval'));

            return $this->backWithFlash('Shift reset successfully.');
        } catch (\Exception $e) {
            Log::error('BreakTimer Reset Error: '.$e->getMessage());

            return $this->backWithFlash('Failed to reset shift.', 'error');
        }
    }

    public function status()
    {
        $user = auth()->user();
        $policy = $this->breakTimerService->getActivePolicy();
        $today = $this->breakTimerService->getShiftDate($policy);

        $activeSession = BreakSession::query()
            ->forUser($user->id)
            ->forDate($today)
            ->active()
            ->first();

        if (! $activeSession) {
            return response()->json(['active' => false]);
        }

        $timing = $this->breakTimerService->getSessionTimingSnapshot($activeSession);

        return response()->json([
            'active' => true,
            'session' => $activeSession,
            'remaining_seconds' => $timing['remaining_seconds'],
            'overage_seconds' => $timing['overage_seconds'],
        ]);
    }
}
