<?php

namespace App\Http\Controllers;

use App\Models\BiometricRecord;
use App\Services\BiometricAnomalyDetector;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BiometricAnomalyController extends Controller
{
    protected BiometricAnomalyDetector $detector;

    public function __construct(BiometricAnomalyDetector $detector)
    {
        $this->detector = $detector;
    }

    /**
     * Display the anomaly detection interface
     */
    public function index()
    {
        return Inertia::render('Attendance/BiometricRecords/Anomalies', [
            'stats' => [
                'total_records' => \App\Models\BiometricRecord::count(),
                'oldest_record' => \App\Models\BiometricRecord::orderBy('datetime')->first()?->datetime,
                'newest_record' => \App\Models\BiometricRecord::orderBy('datetime', 'desc')->first()?->datetime,
            ],
        ]);
    }

    /**
     * Get anomalies for date range
     */
    public function detect(Request $request)
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'anomaly_types' => 'nullable|array',
            'min_severity' => 'nullable|in:low,medium,high',
        ]);

        $startDate = Carbon::parse($request->start_date);
        $endDate = Carbon::parse($request->end_date);

        $allAnomalies = $this->detector->detectAnomalies($startDate, $endDate);

        // Filter by requested types if specified
        $requestedTypes = $request->anomaly_types ?? array_keys($allAnomalies);
        $filteredAnomalies = array_intersect_key($allAnomalies, array_flip($requestedTypes));

        // Flatten and format anomalies for frontend
        $formattedAnomalies = [];
        foreach ($filteredAnomalies as $type => $items) {
            foreach ($items as $anomaly) {
                // Filter by minimum severity if specified
                if ($request->min_severity) {
                    $severityOrder = ['low' => 1, 'medium' => 2, 'high' => 3];
                    $minLevel = $severityOrder[$request->min_severity];
                    $anomalyLevel = $severityOrder[$anomaly['severity']];

                    if ($anomalyLevel < $minLevel) {
                        continue;
                    }
                }

                // Format records based on anomaly type
                $records = $this->formatRecords($anomaly, $type);

                $formattedAnomalies[] = [
                    'type' => $type,
                    'severity' => $anomaly['severity'],
                    'description' => $anomaly['description'],
                    'user' => [
                        'id' => $anomaly['user_id'],
                        'name' => $anomaly['user_name'],
                        'employee_number' => '', // Can be added if needed
                    ],
                    'records' => $records,
                    'details' => $this->extractDetails($anomaly, $type),
                ];
            }
        }

        // Calculate statistics
        $byType = [];
        $bySeverity = ['high' => 0, 'medium' => 0, 'low' => 0];

        foreach ($formattedAnomalies as $anomaly) {
            $byType[$anomaly['type']] = ($byType[$anomaly['type']] ?? 0) + 1;
            $bySeverity[$anomaly['severity']]++;
        }

        $results = [
            'total_anomalies' => count($formattedAnomalies),
            'by_type' => $byType,
            'by_severity' => $bySeverity,
            'anomalies' => $formattedAnomalies,
        ];

        return Inertia::render('Attendance/BiometricRecords/Anomalies', [
            'stats' => [
                'total_records' => BiometricRecord::count(),
                'oldest_record' => BiometricRecord::orderBy('datetime')->first()?->datetime,
                'newest_record' => BiometricRecord::orderBy('datetime', 'desc')->first()?->datetime,
            ],
            'results' => $results,
        ]);
    }

    /**
     * Format records for display
     */
    protected function formatRecords(array $anomaly, string $type): array
    {
        $records = [];

        switch ($type) {
            case 'simultaneous_sites':
                $records = [
                    [
                        'id' => $anomaly['record_1']['id'],
                        'scan_datetime' => $anomaly['record_1']['datetime']->toIso8601String(),
                        'site' => $anomaly['record_1']['site'],
                    ],
                    [
                        'id' => $anomaly['record_2']['id'],
                        'scan_datetime' => $anomaly['record_2']['datetime']->toIso8601String(),
                        'site' => $anomaly['record_2']['site'],
                    ],
                ];
                break;

            case 'duplicate_scans':
                if (isset($anomaly['record_ids'])) {
                    foreach ($anomaly['record_ids'] as $recordId) {
                        $records[] = [
                            'id' => $recordId,
                            'scan_datetime' => $anomaly['datetime']->toIso8601String(),
                            'site' => 'Multiple',
                        ];
                    }
                }
                break;

            case 'unusual_hours':
                $records = [[
                    'id' => $anomaly['record_id'],
                    'scan_datetime' => $anomaly['datetime']->toIso8601String(),
                    'site' => 'N/A',
                ]];
                break;

            case 'excessive_scans':
                if (isset($anomaly['scans'])) {
                    foreach ($anomaly['scans'] as $scan) {
                        $records[] = [
                            'id' => $scan['id'],
                            'scan_datetime' => $scan['datetime']->toIso8601String(),
                            'site' => $scan['site'],
                        ];
                    }
                }
                break;

            case 'impossible_gaps':
                $records = [
                    [
                        'id' => 0,
                        'scan_datetime' => $anomaly['first_scan']->toIso8601String(),
                        'site' => 'First Scan',
                    ],
                    [
                        'id' => 0,
                        'scan_datetime' => $anomaly['last_scan']->toIso8601String(),
                        'site' => 'Last Scan',
                    ],
                ];
                break;
        }

        return $records;
    }

    /**
     * Extract additional details
     */
    protected function extractDetails(array $anomaly, string $type): array
    {
        $details = [];

        switch ($type) {
            case 'simultaneous_sites':
                $details['minutes_apart'] = $anomaly['minutes_apart'];
                break;

            case 'duplicate_scans':
                $details['scan_count'] = $anomaly['scan_count'];
                break;

            case 'excessive_scans':
                $details['scan_count'] = $anomaly['scan_count'];
                $details['date'] = $anomaly['date'];
                break;

            case 'impossible_gaps':
                $details['date'] = $anomaly['date'];
                break;
        }

        return $details;
    }
}
