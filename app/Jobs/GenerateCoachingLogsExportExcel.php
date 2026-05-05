<?php

namespace App\Jobs;

use App\Models\CoachingSession;
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

class GenerateCoachingLogsExportExcel implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  array<int>|null  $campaignIds
     */
    public function __construct(
        protected string $jobId,
        protected ?string $dateFrom = null,
        protected ?string $dateTo = null,
        protected ?array $campaignIds = null,
        protected ?int $teamLeadId = null,
        protected ?string $coachingStatus = null,
    ) {}

    public function handle(): void
    {
        $cacheKey = "coaching_export_job:{$this->jobId}";

        try {
            $this->updateProgress($cacheKey, 5, 'Fetching coaching sessions...');

            $query = CoachingSession::with(['coachee', 'coach', 'complianceReviewer'])
                ->submitted();

            if ($this->dateFrom) {
                $query->where('session_date', '>=', Carbon::parse($this->dateFrom)->toDateString());
            }

            if ($this->dateTo) {
                $query->where('session_date', '<=', Carbon::parse($this->dateTo)->toDateString());
            }

            if ($this->teamLeadId) {
                $query->where('coach_id', $this->teamLeadId);
            }

            if ($this->campaignIds) {
                $campaignIds = $this->campaignIds;
                $query->whereHas('coachee', function ($q) use ($campaignIds) {
                    $q->whereHas('activeSchedule', function ($sq) use ($campaignIds) {
                        $sq->whereIn('campaign_id', $campaignIds);
                    });
                });
            }

            $total = (clone $query)->count();

            $this->updateProgress($cacheKey, 15, "Processing {$total} records...");

            $spreadsheet = new Spreadsheet;
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Coaching Sessions');

            $headers = [
                'Session Date',
                'Coachee Name',
                'Coachee Excluded?',
                'Coach',
                'Purpose',
                'Severity',
                'Agent Profile',
                'Focus Areas',
                'Performance Description',
                'Root Causes',
                'Agent Strengths/Wins',
                'SMART Action Plan',
                'Follow-up Date',
                'Ack Status',
                'Ack Timestamp',
                'Ack Comment',
                'Compliance Status',
                'Compliance Reviewer',
                'Compliance Notes',
                'Created At',
            ];

            $sheet->fromArray($headers, null, 'A1');
            $this->styleHeaderRow($sheet, 'A1:T1');

            $this->updateProgress($cacheKey, 25, 'Writing coaching data...');

            $row = 2;
            $processed = 0;

            $query->orderByDesc('session_date')->chunk(200, function ($records) use ($cacheKey, $total, $sheet, &$row, &$processed) {
                foreach ($records as $record) {
                    $agentName = $record->coachee
                        ? $record->coachee->first_name.' '.$record->coachee->last_name
                        : 'N/A';

                    $teamLeadName = $record->coach
                        ? $record->coach->first_name.' '.$record->coach->last_name
                        : 'N/A';

                    $reviewerName = $record->complianceReviewer
                        ? $record->complianceReviewer->first_name.' '.$record->complianceReviewer->last_name
                        : '';

                    $sheet->fromArray([
                        Carbon::parse($record->session_date)->format('Y-m-d'),
                        $agentName,
                        $record->coachee && $record->coachee->isCoachingExcluded() ? 'Yes' : 'No',
                        $teamLeadName,
                        CoachingSession::PURPOSE_LABELS[$record->purpose] ?? $record->purpose,
                        $record->severity_flag,
                        $this->buildProfileString($record),
                        $this->buildFocusAreasString($record),
                        $record->performance_description,
                        $this->buildRootCausesString($record),
                        $record->agent_strengths_wins ?? '',
                        $record->smart_action_plan,
                        $record->follow_up_date ? Carbon::parse($record->follow_up_date)->format('Y-m-d') : '',
                        $record->ack_status,
                        $record->ack_timestamp ? Carbon::parse($record->ack_timestamp)->format('Y-m-d H:i:s') : '',
                        $record->ack_comment ?? '',
                        str_replace('_', ' ', $record->compliance_status),
                        $reviewerName,
                        $record->compliance_notes ?? '',
                        Carbon::parse($record->created_at)->format('Y-m-d H:i:s'),
                    ], null, 'A'.$row);

                    $row++;
                    $processed++;

                    if ($processed % 50 === 0) {
                        $percent = 25 + intval(($processed / max($total, 1)) * 50);
                        $this->updateProgress($cacheKey, $percent, "Processing record {$processed}/{$total}...");
                    }
                }
            });

            $this->updateProgress($cacheKey, 80, 'Auto-sizing columns...');

            foreach (range('A', 'T') as $col) {
                $sheet->getColumnDimension($col)->setAutoSize(true);
            }

            // Wrap text for long columns
            foreach (['I', 'J', 'K', 'L'] as $col) {
                $sheet->getStyle("{$col}2:{$col}{$row}")
                    ->getAlignment()
                    ->setWrapText(true);
                $sheet->getColumnDimension($col)->setAutoSize(false);
                $sheet->getColumnDimension($col)->setWidth(40);
            }

            $this->updateProgress($cacheKey, 90, 'Saving Excel file...');

            $dateRangeStr = 'all_records';
            if ($this->dateFrom && $this->dateTo) {
                $dateRangeStr = $this->dateFrom.'_to_'.$this->dateTo;
            } elseif ($this->dateFrom) {
                $dateRangeStr = 'from_'.$this->dateFrom;
            } elseif ($this->dateTo) {
                $dateRangeStr = 'to_'.$this->dateTo;
            }

            $filename = sprintf('coaching_export_%s_%s.xlsx', $dateRangeStr, $this->jobId);

            $tempDir = storage_path('app/temp');
            if (! is_dir($tempDir)) {
                mkdir($tempDir, 0777, true);
            }

            $filePath = $tempDir.'/'.$filename;
            $writer = new Xlsx($spreadsheet);
            $writer->save($filePath);

            $this->updateProgress($cacheKey, 100, 'Finished', true, url("/coaching/export/download/{$this->jobId}"));

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
        ], 600);
    }

    protected function styleHeaderRow($sheet, string $range): void
    {
        $headerStyle = $sheet->getStyle($range);
        $headerStyle->getFont()->setBold(true);
        $headerStyle->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FF2196F3');
        $headerStyle->getFont()->getColor()->setARGB('FFFFFFFF');
        $headerStyle->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $headerStyle->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    }

    protected function buildProfileString(CoachingSession $session): string
    {
        $parts = [];
        if ($session->profile_new_hire) {
            $parts[] = 'New Hire';
        }
        if ($session->profile_tenured) {
            $parts[] = 'Tenured';
        }
        if ($session->profile_returning) {
            $parts[] = 'Returning';
        }
        if ($session->profile_previously_coached_same_issue) {
            $parts[] = 'Previously Coached (Same Issue)';
        }

        return implode(', ', $parts) ?: 'N/A';
    }

    protected function buildFocusAreasString(CoachingSession $session): string
    {
        $areas = [];
        if ($session->focus_attendance_tardiness) {
            $areas[] = 'Attendance/Tardiness';
        }
        if ($session->focus_productivity) {
            $areas[] = 'Productivity';
        }
        if ($session->focus_compliance) {
            $areas[] = 'Compliance';
        }
        if ($session->focus_callouts) {
            $areas[] = 'Callouts';
        }
        if ($session->focus_recognition_milestones) {
            $areas[] = 'Recognition/Milestones';
        }
        if ($session->focus_growth_development) {
            $areas[] = 'Growth/Development';
        }
        if ($session->focus_other) {
            $areas[] = 'Other: '.($session->focus_other_notes ?? '');
        }

        return implode(', ', $areas) ?: 'N/A';
    }

    protected function buildRootCausesString(CoachingSession $session): string
    {
        $causes = [];
        if ($session->root_cause_lack_of_skills) {
            $causes[] = 'Lack of Skills / Knowledge';
        }
        if ($session->root_cause_lack_of_clarity) {
            $causes[] = 'Lack of Clarity on Expectations';
        }
        if ($session->root_cause_personal_issues) {
            $causes[] = 'Personal Issues';
        }
        if ($session->root_cause_motivation_engagement) {
            $causes[] = 'Motivation / Engagement';
        }
        if ($session->root_cause_health_fatigue) {
            $causes[] = 'Health / Fatigue';
        }
        if ($session->root_cause_workload_process) {
            $causes[] = 'Workload or Process Issues';
        }
        if ($session->root_cause_peer_conflict) {
            $causes[] = 'Peer / Team Conflict';
        }
        if ($session->root_cause_others) {
            $causes[] = 'Progress Update';
        }

        return implode(', ', $causes) ?: 'N/A';
    }
}
