<?php

namespace App\Http\Controllers;

use App\Http\Requests\BreakSessionRequest;
use App\Models\BreakSession;
use App\Services\BreakTimerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class BreakTimerController extends Controller
{
    public function __construct(protected BreakTimerService $breakTimerService) {}

    public function index()
    {
        $user = auth()->user();
        $today = now()->toDateString();

        $policy = $this->breakTimerService->getActivePolicy();
        $todaySessions = $this->breakTimerService->getTodaySessions($user->id, $today);
        $activeSession = $this->breakTimerService->getActiveSession($todaySessions);
        $breaksUsed = $this->breakTimerService->getBreaksUsed($todaySessions);
        $lunchUsed = $this->breakTimerService->isLunchUsed($todaySessions);

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
        $today = now()->toDateString();
        $validated = $request->validated();

        $policy = $this->breakTimerService->getActivePolicy();

        if (! $policy) {
            return redirect()->back()->with('flash', [
                'message' => 'No active break policy found. Contact your administrator.',
                'type' => 'error',
            ]);
        }

        // Check for existing active session
        $existing = BreakSession::query()
            ->forUser($user->id)
            ->forDate($today)
            ->active()
            ->exists();

        if ($existing) {
            return redirect()->back()->with('flash', [
                'message' => 'You already have an active break/lunch session.',
                'type' => 'error',
            ]);
        }

        try {
            $result = $this->breakTimerService->validateAndGetDuration(
                $validated['type'],
                $user->id,
                $today,
                $policy,
                $validated['combined_break_count'] ?? null,
            );

            $this->breakTimerService->startSession(
                $user->id,
                $result['type'],
                $result['duration_seconds'],
                $policy->id,
                $validated['station'] ?? null,
                $today,
                $result['combined_break_count'] ?? null,
            );

            return redirect()->back()->with('flash', [
                'message' => ucfirst(str_replace('_', ' ', $result['type'])).' started.',
                'type' => 'success',
            ]);
        } catch (\RuntimeException $e) {
            return redirect()->back()->with('flash', [
                'message' => $e->getMessage(),
                'type' => 'error',
            ]);
        } catch (\Exception $e) {
            Log::error('BreakTimer Start Error: '.$e->getMessage());

            return redirect()->back()->with('flash', [
                'message' => 'Failed to start break. Please try again.',
                'type' => 'error',
            ]);
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
            return redirect()->back()->with('flash', [
                'message' => 'This session is not active.',
                'type' => 'error',
            ]);
        }

        try {
            $this->breakTimerService->pauseSession($breakSession, $request->input('reason'));

            return redirect()->back()->with('flash', [
                'message' => 'Timer paused.',
                'type' => 'success',
            ]);
        } catch (\Exception $e) {
            Log::error('BreakTimer Pause Error: '.$e->getMessage());

            return redirect()->back()->with('flash', [
                'message' => 'Failed to pause timer.',
                'type' => 'error',
            ]);
        }
    }

    public function resume(BreakSession $breakSession)
    {
        if ($breakSession->user_id !== auth()->id()) {
            abort(403);
        }

        if ($breakSession->status !== 'paused') {
            return redirect()->back()->with('flash', [
                'message' => 'This session is not paused.',
                'type' => 'error',
            ]);
        }

        try {
            $this->breakTimerService->resumeSession($breakSession);

            return redirect()->back()->with('flash', [
                'message' => 'Timer resumed.',
                'type' => 'success',
            ]);
        } catch (\Exception $e) {
            Log::error('BreakTimer Resume Error: '.$e->getMessage());

            return redirect()->back()->with('flash', [
                'message' => 'Failed to resume timer.',
                'type' => 'error',
            ]);
        }
    }

    public function end(BreakSession $breakSession)
    {
        if ($breakSession->user_id !== auth()->id()) {
            abort(403);
        }

        if (! in_array($breakSession->status, ['active', 'paused'])) {
            return redirect()->back()->with('flash', [
                'message' => 'This session is already ended.',
                'type' => 'error',
            ]);
        }

        try {
            $this->breakTimerService->endSession($breakSession);

            return redirect()->back()->with('flash', [
                'message' => 'Break ended successfully.',
                'type' => 'success',
            ]);
        } catch (\Exception $e) {
            Log::error('BreakTimer End Error: '.$e->getMessage());

            return redirect()->back()->with('flash', [
                'message' => 'Failed to end break.',
                'type' => 'error',
            ]);
        }
    }

    public function reset(Request $request)
    {
        $request->validate([
            'approval' => ['required', 'string', 'max:500'],
        ]);

        $user = auth()->user();
        $today = now()->toDateString();

        try {
            $this->breakTimerService->resetShift($user->id, $today, $request->input('approval'));

            return redirect()->back()->with('flash', [
                'message' => 'Shift reset successfully.',
                'type' => 'success',
            ]);
        } catch (\Exception $e) {
            Log::error('BreakTimer Reset Error: '.$e->getMessage());

            return redirect()->back()->with('flash', [
                'message' => 'Failed to reset shift.',
                'type' => 'error',
            ]);
        }
    }

    public function status()
    {
        $user = auth()->user();
        $today = now()->toDateString();

        $activeSession = BreakSession::query()
            ->forUser($user->id)
            ->forDate($today)
            ->active()
            ->with('breakEvents')
            ->first();

        if (! $activeSession) {
            return response()->json(['active' => false]);
        }

        // Calculate real-time remaining seconds
        $totalPaused = $activeSession->total_paused_seconds;
        if ($activeSession->status === 'paused') {
            $lastPauseEvent = $activeSession->breakEvents()
                ->where('action', 'pause')
                ->latest('occurred_at')
                ->first();

            if ($lastPauseEvent) {
                $totalPaused += now()->diffInSeconds($lastPauseEvent->occurred_at);
            }
        }

        $elapsed = $activeSession->status === 'paused'
            ? $activeSession->duration_seconds - $activeSession->remaining_seconds
            : now()->diffInSeconds($activeSession->started_at) - $totalPaused;

        $remaining = max(0, $activeSession->duration_seconds - $elapsed);
        $overage = $elapsed > $activeSession->duration_seconds
            ? $elapsed - $activeSession->duration_seconds
            : 0;

        return response()->json([
            'active' => true,
            'session' => $activeSession,
            'remaining_seconds' => $remaining,
            'overage_seconds' => $overage,
        ]);
    }
}
