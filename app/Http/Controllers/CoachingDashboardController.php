<?php

namespace App\Http\Controllers;

use App\Http\Traits\RedirectsWithFlashMessages;
use App\Jobs\GenerateCoachingLogsExportExcel;
use App\Models\Campaign;
use App\Models\CoachingSession;
use App\Models\CoachingStatusSetting;
use App\Models\User;
use App\Services\CoachingDashboardService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;

class CoachingDashboardController extends Controller
{
    use RedirectsWithFlashMessages;

    public function __construct(
        protected CoachingDashboardService $dashboardService,
    ) {}

    /**
     * Display the coaching dashboard (routes to correct view by role).
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', CoachingSession::class);

        $user = auth()->user();

        if ($user->role === 'Agent') {
            return $this->agentDashboard($request);
        }

        if ($user->role === 'Team Lead') {
            return $this->teamLeadDashboard($request);
        }

        // Admin, HR, Super Admin → compliance/admin dashboard
        return $this->complianceDashboard($request);
    }

    /**
     * Agent's coaching dashboard / my coaching logs.
     */
    protected function agentDashboard(Request $request)
    {
        $user = auth()->user();
        $summary = $this->dashboardService->getAgentCoachingSummary($user->id);

        $sessions = CoachingSession::with(['teamLead'])
            ->forAgent($user->id)
            ->orderByDesc('session_date')
            ->paginate(15)
            ->withQueryString();

        $pendingSessions = CoachingSession::forAgent($user->id)
            ->pending()
            ->count();

        return Inertia::render('Coaching/MyCoachingLogs/Index', [
            'summary' => $summary,
            'sessions' => $sessions,
            'pendingSessions' => $pendingSessions,
            'purposes' => CoachingSession::PURPOSE_LABELS,
            'statusColors' => CoachingDashboardService::STATUS_COLORS,
        ]);
    }

    /**
     * Team Lead's coaching dashboard.
     */
    protected function teamLeadDashboard(Request $request)
    {
        $user = auth()->user();

        $filters = array_filter([
            'coaching_status' => $request->input('coaching_status'),
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
        ]);

        $dashboardData = $this->dashboardService->getTeamLeadDashboardData($user, $filters ?: null);

        // Get recent sessions created by this TL
        $recentSessions = CoachingSession::with(['agent'])
            ->forTeamLead($user->id)
            ->orderByDesc('session_date')
            ->paginate(15)
            ->withQueryString();

        $campaignName = $user->activeSchedule?->campaign?->name ?? 'N/A';

        return Inertia::render('Coaching/Dashboard/Index', [
            'dashboardData' => $dashboardData,
            'recentSessions' => $recentSessions,
            'campaignName' => $campaignName,
            'filters' => $request->only(['coaching_status', 'date_from', 'date_to']),
            'statusColors' => CoachingDashboardService::STATUS_COLORS,
            'purposes' => CoachingSession::PURPOSE_LABELS,
        ]);
    }

    /**
     * Compliance / Admin coaching dashboard.
     */
    protected function complianceDashboard(Request $request)
    {
        $filters = array_filter([
            'campaign_id' => $request->input('campaign_id'),
            'team_lead_id' => $request->input('team_lead_id'),
            'coaching_status' => $request->input('coaching_status'),
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
        ]);

        $dashboardData = $this->dashboardService->getComplianceDashboardData($filters ?: null);
        $queueData = $this->dashboardService->getComplianceQueueData($filters ?: null);

        // Get campaigns and team leads for filter dropdowns
        $campaigns = Campaign::orderBy('name')->get(['id', 'name']);
        $teamLeads = User::where('role', 'Team Lead')
            ->where('is_approved', true)
            ->where('is_active', true)
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'middle_name', 'last_name']);

        return Inertia::render('Coaching/Admin/Index', [
            'dashboardData' => $dashboardData,
            'queueData' => $queueData,
            'campaigns' => $campaigns,
            'teamLeads' => $teamLeads,
            'filters' => $request->only(['campaign_id', 'team_lead_id', 'coaching_status', 'date_from', 'date_to']),
            'statusColors' => CoachingDashboardService::STATUS_COLORS,
            'purposes' => CoachingSession::PURPOSE_LABELS,
        ]);
    }

    /**
     * Manage coaching status settings (thresholds).
     */
    public function settings(Request $request)
    {
        $this->authorize('manageSettings', CoachingSession::class);

        $settings = CoachingStatusSetting::orderBy('key')->get();
        $defaults = CoachingStatusSetting::DEFAULTS;

        return Inertia::render('Coaching/Settings/Index', [
            'settings' => $settings,
            'defaults' => $defaults,
        ]);
    }

    /**
     * Update coaching status settings.
     */
    public function updateSettings(Request $request)
    {
        $this->authorize('manageSettings', CoachingSession::class);

        $validated = $request->validate([
            'settings' => ['required', 'array'],
            'settings.*.key' => ['required', 'string'],
            'settings.*.value' => ['required', 'integer', 'min:1', 'max:365'],
        ]);

        try {
            DB::transaction(function () use ($validated) {
                foreach ($validated['settings'] as $setting) {
                    CoachingStatusSetting::updateOrCreate(
                        ['key' => $setting['key']],
                        ['value' => $setting['value']],
                    );
                }
            });

            // Clear the threshold cache
            CoachingStatusSetting::clearCache();

            return $this->backWithFlash('Coaching settings updated successfully.');
        } catch (\Exception $e) {
            Log::error('CoachingDashboardController@updateSettings Error: '.$e->getMessage());

            return $this->backWithFlash('Failed to update coaching settings.', 'error');
        }
    }

    /**
     * Start a coaching logs export job.
     */
    public function startExport(Request $request)
    {
        $this->authorize('export', CoachingSession::class);

        $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'campaign_id' => 'nullable|exists:campaigns,id',
            'team_lead_id' => 'nullable|exists:users,id',
        ]);

        $jobId = (string) Str::uuid();

        $job = new GenerateCoachingLogsExportExcel(
            $jobId,
            $request->input('date_from'),
            $request->input('date_to'),
            $request->input('campaign_id') ? (int) $request->input('campaign_id') : null,
            $request->input('team_lead_id') ? (int) $request->input('team_lead_id') : null,
        );

        if (config('queue.default') === 'sync') {
            Bus::dispatchSync($job);
        } else {
            Bus::dispatch($job);
        }

        return response()->json(['jobId' => $jobId]);
    }

    /**
     * Check coaching export job progress.
     */
    public function exportProgress(string $jobId)
    {
        $cacheKey = "coaching_export_job:{$jobId}";
        $progress = Cache::get($cacheKey, [
            'percent' => 0,
            'status' => 'Not started',
            'finished' => false,
            'downloadUrl' => null,
        ]);

        return response()->json($progress);
    }

    /**
     * Download the generated coaching export file.
     */
    public function downloadExport(string $jobId)
    {
        $cacheKey = "coaching_export_job:{$jobId}";

        $tempDir = storage_path('app/temp');
        $pattern = $tempDir."/coaching_export_*_{$jobId}.xlsx";
        $files = glob($pattern);

        if (empty($files)) {
            Cache::forget($cacheKey);
            abort(404, 'Export file not found. Please generate a new export.');
        }

        $filePath = $files[0];
        $filename = basename($filePath);

        Cache::forget($cacheKey);

        return response()->download($filePath, $filename)->deleteFileAfterSend(true);
    }
}
