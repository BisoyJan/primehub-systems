<?php

namespace Database\Seeders;

use App\Models\BreakEvent;
use App\Models\BreakPolicy;
use App\Models\BreakSession;
use App\Models\Campaign;
use App\Models\User;
use App\Services\BreakTimerService;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Puts a handful of real agents on live (active / paused / overbreak) breaks
 * for the current shift date so the dashboard "On Break Now" indicator can be
 * seen working. Re-running replaces previously seeded demo sessions.
 *
 * Seed:    php artisan db:seed --class=DemoLiveBreaksSeeder
 * Cleanup: php artisan tinker --execute="App\Models\BreakSession::where('session_id','like','DEMO-%')->delete();"
 */
class DemoLiveBreaksSeeder extends Seeder
{
    use WithoutModelEvents;

    private const SESSION_PREFIX = 'DEMO-';

    public function run(): void
    {
        $removed = $this->removePreviousDemoSessions();

        $service = app(BreakTimerService::class);
        $policy = $service->getActivePolicy() ?? BreakPolicy::query()->first();

        if (! $policy) {
            $this->command?->error('No break policy found. Run BreakPolicySeeder first.');

            return;
        }

        $shiftDate = $service->getShiftDate($policy);
        $agents = $this->pickAgents(7);

        if ($agents->isEmpty()) {
            $this->command?->error('No approved agents found to put on break.');

            return;
        }

        $now = Carbon::now();
        $scenarios = $this->scenarios($now);

        $agents->values()->each(function (User $agent, int $index) use ($scenarios, $policy, $shiftDate, $now) {
            $scenario = $scenarios[$index % count($scenarios)];
            $this->createSession($agent, $scenario, $policy, $shiftDate, $now, $index + 1);
        });

        $this->command?->info(sprintf(
            'Seeded %d live demo break session(s) for shift date %s (removed %d old).',
            $agents->count(),
            $shiftDate,
            $removed,
        ));

        $this->command?->table(
            ['Agent', 'Campaign', 'Type', 'Status', 'Detail'],
            $agents->values()->map(function (User $agent, int $index) use ($scenarios) {
                $scenario = $scenarios[$index % count($scenarios)];

                return [
                    "{$agent->first_name} {$agent->last_name}",
                    $agent->activeSchedule?->campaign?->name ?? '—',
                    $scenario['type'],
                    $scenario['status'],
                    $scenario['label'],
                ];
            })->toArray(),
        );
    }

    private function removePreviousDemoSessions(): int
    {
        return BreakSession::query()
            ->where('session_id', 'like', self::SESSION_PREFIX.'%')
            ->delete();
    }

    /**
     * Prefer agents spread across campaigns that have a Team Lead assigned so
     * TLs see only their own people while admins see everyone.
     *
     * @return Collection<int, User>
     */
    private function pickAgents(int $limit): Collection
    {
        $teamLeadCampaignIds = Campaign::query()
            ->whereHas('teamLeads', fn ($q) => $q->where('role', 'Team Lead'))
            ->pluck('id');

        $picked = collect();

        foreach ($teamLeadCampaignIds as $campaignId) {
            if ($picked->count() >= $limit) {
                break;
            }

            $agents = User::query()
                ->with('activeSchedule.campaign')
                ->where('is_approved', true)
                ->where('role', 'Agent')
                ->whereNotIn('id', $picked->pluck('id'))
                ->whereHas('activeSchedule', fn ($q) => $q->where('campaign_id', $campaignId))
                ->orderBy('first_name')
                ->limit(2)
                ->get();

            $picked = $picked->merge($agents->take($limit - $picked->count()));
        }

        if ($picked->count() < $limit) {
            $fallback = User::query()
                ->with('activeSchedule.campaign')
                ->where('is_approved', true)
                ->where('role', 'Agent')
                ->whereNotIn('id', $picked->pluck('id'))
                ->orderBy('first_name')
                ->limit($limit - $picked->count())
                ->get();

            $picked = $picked->merge($fallback);
        }

        return $picked;
    }

    /**
     * @return array<int, array{
     *     type: string,
     *     status: string,
     *     duration: int,
     *     started_at: Carbon,
     *     paused_at: ?Carbon,
     *     pause_reason: ?string,
     *     prior_pauses: array<int, array{at: Carbon, resumed_at: Carbon, reason: string}>,
     *     label: string
     * }>
     */
    private function scenarios(Carbon $now): array
    {
        return [
            [
                'type' => '1st_break',
                'status' => 'active',
                'duration' => 900,
                'started_at' => $now->copy()->subMinutes(4),
                'paused_at' => null,
                'pause_reason' => null,
                'prior_pauses' => [],
                'label' => '~11:00 left',
            ],
            [
                'type' => '1st_break',
                'status' => 'paused',
                'duration' => 900,
                'started_at' => $now->copy()->subMinutes(10),
                'paused_at' => $now->copy()->subMinutes(3),
                'pause_reason' => 'Coaching',
                'prior_pauses' => [],
                'label' => 'paused 3m ago, 08:00 frozen',
            ],
            [
                'type' => 'lunch',
                'status' => 'active',
                'duration' => 3600,
                'started_at' => $now->copy()->subMinutes(22),
                'paused_at' => null,
                'pause_reason' => null,
                'prior_pauses' => [],
                'label' => '~38:00 left',
            ],
            [
                'type' => '1st_break',
                'status' => 'active',
                'duration' => 900,
                'started_at' => $now->copy()->subMinutes(18),
                'paused_at' => null,
                'pause_reason' => null,
                'prior_pauses' => [],
                'label' => 'OVERBREAK +03:00',
            ],
            [
                'type' => '2nd_break',
                'status' => 'active',
                'duration' => 900,
                'started_at' => $now->copy()->subMinutes(13)->subSeconds(30),
                'paused_at' => null,
                'pause_reason' => null,
                'prior_pauses' => [],
                'label' => '~01:30 left (about to expire)',
            ],
            [
                'type' => 'lunch',
                'status' => 'paused',
                'duration' => 3600,
                'started_at' => $now->copy()->subMinutes(25),
                'paused_at' => $now->copy()->subMinutes(5),
                'pause_reason' => 'Manager Request',
                'prior_pauses' => [],
                'label' => 'paused 5m ago, 40:00 frozen',
            ],
            [
                'type' => '2nd_break',
                'status' => 'active',
                'duration' => 900,
                'started_at' => $now->copy()->subMinutes(12),
                'paused_at' => null,
                'pause_reason' => null,
                'prior_pauses' => [
                    [
                        'at' => $now->copy()->subMinutes(8),
                        'resumed_at' => $now->copy()->subMinutes(6),
                        'reason' => 'Bathroom',
                    ],
                ],
                'label' => 'resumed after 2m pause, ~05:00 left',
            ],
        ];
    }

    /**
     * @param  array{
     *     type: string,
     *     status: string,
     *     duration: int,
     *     started_at: Carbon,
     *     paused_at: ?Carbon,
     *     pause_reason: ?string,
     *     prior_pauses: array<int, array{at: Carbon, resumed_at: Carbon, reason: string}>,
     *     label: string
     * }  $scenario
     */
    private function createSession(
        User $agent,
        array $scenario,
        BreakPolicy $policy,
        string $shiftDate,
        Carbon $now,
        int $index,
    ): void {
        $duration = $scenario['duration'];
        $startedAt = $scenario['started_at'];

        $totalPaused = 0;
        foreach ($scenario['prior_pauses'] as $pause) {
            $totalPaused += (int) $pause['at']->diffInSeconds($pause['resumed_at'], absolute: true);
        }

        $isPaused = $scenario['status'] === 'paused';
        $pausedAt = $scenario['paused_at'];
        $elapsedAtPause = $isPaused && $pausedAt
            ? (int) $startedAt->diffInSeconds($pausedAt, absolute: true) - $totalPaused
            : null;
        $remaining = $isPaused
            ? max(0, $duration - (int) $elapsedAtPause)
            : $duration;

        $prefix = strtoupper(str_replace('_', '', $scenario['type']));

        $session = BreakSession::create([
            'session_id' => self::SESSION_PREFIX."{$prefix}-{$agent->id}-{$now->timestamp}-{$index}",
            'user_id' => $agent->id,
            'station' => sprintf('ST-%02d', $index),
            'break_policy_id' => $policy->id,
            'type' => $scenario['type'],
            'status' => $scenario['status'],
            'duration_seconds' => $duration,
            'started_at' => $startedAt,
            'remaining_seconds' => $remaining,
            'overage_seconds' => 0,
            'total_paused_seconds' => $totalPaused,
            'last_pause_reason' => $isPaused ? $scenario['pause_reason'] : ($scenario['prior_pauses'][0]['reason'] ?? null),
            'shift_date' => $shiftDate,
        ]);

        BreakEvent::create([
            'break_session_id' => $session->id,
            'action' => 'start',
            'remaining_seconds' => $duration,
            'overage_seconds' => 0,
            'occurred_at' => $startedAt,
        ]);

        $runningPaused = 0;
        foreach ($scenario['prior_pauses'] as $pause) {
            $remainingAtPause = max(0, $duration - ((int) $startedAt->diffInSeconds($pause['at'], absolute: true) - $runningPaused));

            BreakEvent::create([
                'break_session_id' => $session->id,
                'action' => 'pause',
                'remaining_seconds' => $remainingAtPause,
                'overage_seconds' => 0,
                'reason' => $pause['reason'],
                'occurred_at' => $pause['at'],
            ]);

            BreakEvent::create([
                'break_session_id' => $session->id,
                'action' => 'resume',
                'remaining_seconds' => $remainingAtPause,
                'overage_seconds' => 0,
                'reason' => $pause['reason'],
                'occurred_at' => $pause['resumed_at'],
            ]);

            $runningPaused += (int) $pause['at']->diffInSeconds($pause['resumed_at'], absolute: true);
        }

        if ($isPaused && $pausedAt) {
            BreakEvent::create([
                'break_session_id' => $session->id,
                'action' => 'pause',
                'remaining_seconds' => $remaining,
                'overage_seconds' => 0,
                'reason' => $scenario['pause_reason'],
                'occurred_at' => $pausedAt,
            ]);
        }
    }
}
