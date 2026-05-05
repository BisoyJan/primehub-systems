<?php

namespace App\Jobs;

use App\Models\CoachingSession;
use App\Models\CoachingStatusSetting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class GenerateCampaignCoachingCompletionExportExcel implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  array<int>|null  $campaignIds
     */
    public function __construct(
        protected string $jobId,
        protected ?array $campaignIds = null,
        protected ?int $coachId = null,
    ) {}

    public function handle(): void
    {
        $cacheKey = "campaign_completion_export_job:{$this->jobId}";

        try {
            $this->updateProgress($cacheKey, 5, 'Fetching agents...');

            $monthlyTarget = (int) CoachingStatusSetting::getThreshold('monthly_session_target') ?: 4;
            $today = Carbon::today();
            $monthStart = $today->copy()->startOfMonth();
            $monthEnd = $today->copy()->endOfMonth();
            $weeksElapsed = (int) min((int) ceil($today->day / 7), $monthlyTarget);

            $userQuery = User::where('role', 'Agent')
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->with(['activeSchedule.campaign', 'activeCoachingExclusion']);

            if ($this->campaignIds) {
                $campaignIds = $this->campaignIds;
                $userQuery->whereHas('activeSchedule', fn ($q) => $q->whereIn('campaign_id', $campaignIds));
            }

            if ($this->coachId) {
                $coachId = $this->coachId;
                $userQuery->whereHas('coachingSessionsAsCoachee', fn ($q) => $q->where('coach_id', $coachId));
            }

            $agents = $userQuery->get();

            $this->updateProgress($cacheKey, 25, 'Counting sessions this month...');

            $monthlySessionCounts = CoachingSession::submitted()
                ->whereIn('coachee_id', $agents->pluck('id'))
                ->whereBetween('session_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
                ->selectRaw('coachee_id, COUNT(*) as cnt')
                ->groupBy('coachee_id')
                ->pluck('cnt', 'coachee_id');

            $spreadsheet = new Spreadsheet;

            $perAgentSheet = $spreadsheet->getActiveSheet();
            $perAgentSheet->setTitle('Per Agent');
            $perAgentSheet->fromArray([
                'Campaign',
                'Agent',
                'Excluded?',
                'Schedule Effective Date',
                'Eligible (active full month)',
                'Sessions This Month',
                "Target ({$monthlyTarget}/mo)",
                'Expected So Far',
                'Behind Weekly?',
                'Fully Coached?',
                'Completion %',
            ], null, 'A1');
            $this->styleHeaderRow($perAgentSheet, 'A1:K1');

            $row = 2;
            $byCampaign = [];

            foreach ($agents as $agent) {
                $campaignName = $agent->activeSchedule?->campaign?->name ?? 'N/A';
                $eff = $agent->activeSchedule?->effective_date;
                $isExcluded = $agent->activeCoachingExclusion !== null;
                $eligible = ! $isExcluded && ($eff === null || $eff->lte($monthStart));
                $sessions = (int) ($monthlySessionCounts[$agent->id] ?? 0);
                $capped = min($sessions, $monthlyTarget);
                $rate = $monthlyTarget > 0 ? (int) round(($capped / $monthlyTarget) * 100) : 0;

                $perAgentSheet->fromArray([
                    $campaignName,
                    $agent->first_name.' '.$agent->last_name,
                    $isExcluded ? ($agent->activeCoachingExclusion->reason ?? 'Yes') : 'No',
                    $eff?->toDateString() ?? '',
                    $eligible ? 'Yes' : 'No',
                    $sessions,
                    $monthlyTarget,
                    $weeksElapsed,
                    $eligible && $sessions < $weeksElapsed ? 'Yes' : '',
                    $eligible && $sessions >= $monthlyTarget ? 'Yes' : '',
                    $rate.'%',
                ], null, 'A'.$row);

                if ($eligible) {
                    if (! isset($byCampaign[$campaignName])) {
                        $byCampaign[$campaignName] = [
                            'eligible' => 0,
                            'capped' => 0,
                            'expected' => 0,
                            'sessions' => 0,
                            'fully_coached' => 0,
                            'behind_weekly' => 0,
                        ];
                    }
                    $byCampaign[$campaignName]['eligible']++;
                    $byCampaign[$campaignName]['capped'] += $capped;
                    $byCampaign[$campaignName]['expected'] += $monthlyTarget;
                    $byCampaign[$campaignName]['sessions'] += $sessions;
                    if ($sessions >= $monthlyTarget) {
                        $byCampaign[$campaignName]['fully_coached']++;
                    }
                    if ($sessions < $weeksElapsed) {
                        $byCampaign[$campaignName]['behind_weekly']++;
                    }
                }

                $row++;
            }

            $this->updateProgress($cacheKey, 70, 'Building summary sheet...');

            $summarySheet = $spreadsheet->createSheet();
            $summarySheet->setTitle('Per Campaign');
            $summarySheet->fromArray([
                'Campaign',
                'Eligible Agents',
                'Sessions This Month',
                'Capped Sessions',
                'Expected Sessions',
                'Fully Coached',
                'Behind Weekly',
                'Completion %',
            ], null, 'A1');
            $this->styleHeaderRow($summarySheet, 'A1:H1');

            $sumRow = 2;
            ksort($byCampaign);
            foreach ($byCampaign as $name => $stats) {
                $rate = $stats['expected'] > 0 ? (int) round(($stats['capped'] / $stats['expected']) * 100) : 0;
                $summarySheet->fromArray([
                    $name,
                    $stats['eligible'],
                    $stats['sessions'],
                    $stats['capped'],
                    $stats['expected'],
                    $stats['fully_coached'],
                    $stats['behind_weekly'],
                    $rate.'%',
                ], null, 'A'.$sumRow);
                $sumRow++;
            }

            $this->updateProgress($cacheKey, 85, 'Auto-sizing columns...');

            foreach (range('A', 'K') as $col) {
                $perAgentSheet->getColumnDimension($col)->setAutoSize(true);
            }
            foreach (range('A', 'H') as $col) {
                $summarySheet->getColumnDimension($col)->setAutoSize(true);
            }

            $this->updateProgress($cacheKey, 92, 'Saving Excel file...');

            $tempDir = storage_path('app/temp');
            if (! is_dir($tempDir)) {
                mkdir($tempDir, 0777, true);
            }

            $filename = sprintf(
                'campaign_coaching_completion_%s_%s.xlsx',
                $today->format('Y-m'),
                $this->jobId,
            );
            $filePath = $tempDir.'/'.$filename;

            $writer = new Xlsx($spreadsheet);
            $writer->save($filePath);

            $this->updateProgress(
                $cacheKey,
                100,
                'Finished',
                true,
                url("/coaching/campaign-completion/export/download/{$this->jobId}"),
            );
        } catch (\Exception $e) {
            Cache::put($cacheKey, [
                'percent' => 0,
                'status' => 'Error: '.$e->getMessage(),
                'finished' => true,
                'downloadUrl' => null,
                'error' => true,
            ], 600);
        }
    }

    protected function updateProgress(string $cacheKey, int $percent, string $status, bool $finished = false, ?string $downloadUrl = null): void
    {
        Cache::put($cacheKey, [
            'percent' => $percent,
            'status' => $status,
            'finished' => $finished,
            'downloadUrl' => $downloadUrl,
        ], 3600);
    }

    protected function styleHeaderRow($sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F2937']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '374151']]],
        ]);
    }
}
