<?php

namespace App\Http\Controllers;

use App\Models\BiometricRecord;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class BiometricExportController extends Controller
{
    /**
     * Display the export interface
     */
    public function index()
    {
        // Get all users who have biometric records
        $userIds = BiometricRecord::distinct()->pluck('user_id')->filter();

        $users = User::whereIn('id', $userIds)
            ->select('id', 'first_name', 'last_name')
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->map(function ($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->first_name . ' ' . $user->last_name,
                    'employee_number' => (string) $user->id, // Use user ID as identifier
                ];
            });

        // Get all sites that have biometric records
        $siteIds = BiometricRecord::distinct()->pluck('site_id')->filter();

        $sites = Site::whereIn('id', $siteIds)
            ->select('id', 'name')
            ->orderBy('name')
            ->get();

        return Inertia::render('Attendance/BiometricRecords/Export', [
            'users' => $users,
            'sites' => $sites,
        ]);
    }

    /**
     * Export biometric records
     */
    public function export(Request $request)
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'format' => 'required|in:csv,xlsx',
            'user_ids' => 'nullable|array',
            'user_ids.*' => 'exists:users,id',
            'site_ids' => 'nullable|array',
            'site_ids.*' => 'exists:sites,id',
        ]);

        $startDate = Carbon::parse($request->start_date);
        $endDate = Carbon::parse($request->end_date);

        // Build query
        $query = BiometricRecord::whereBetween('record_date', [$startDate, $endDate])
            ->with(['user:id,first_name,last_name', 'site:id,name', 'attendanceUpload:id,original_filename']);

        if ($request->user_ids) {
            $query->whereIn('user_id', $request->user_ids);
        }

        if ($request->site_ids) {
            $query->whereIn('site_id', $request->site_ids);
        }

        $records = $query->orderBy('datetime')->get();

        // Generate filename
        $format = $request->input('format');
        $filename = sprintf(
            'biometric_export_%s_to_%s.%s',
            $startDate->format('Y-m-d'),
            $endDate->format('Y-m-d'),
            $format
        );

        if ($format === 'xlsx') {
            return $this->exportExcel($records, $filename);
        }

        return $this->exportCsv($records, $filename);
    }

    /**
     * Export as CSV
     */
    protected function exportCsv($records, $filename)
    {
        $handle = fopen('php://temp', 'w');

        // Headers
        fputcsv($handle, [
            'User ID',
            'Employee Name',
            'Date',
            'Time',
            'Site',
            'Upload File',
            'Upload ID',
        ]);

        // Data
        foreach ($records as $record) {
            fputcsv($handle, [
                $record->user_id ?? 'N/A',
                $record->user ? $record->user->first_name . ' ' . $record->user->last_name : $record->employee_name,
                $record->datetime->format('Y-m-d'),
                $record->datetime->format('H:i:s'),
                $record->site?->name ?? 'Unknown',
                $record->attendanceUpload?->original_filename ?? 'N/A',
                $record->attendance_upload_id,
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Export as Excel
     */
    protected function exportExcel($records, $filename)
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Set headers
        $headers = [
            'User ID',
            'Employee Name',
            'Date',
            'Time',
            'Site',
            'Upload File',
            'Upload ID',
        ];
        $sheet->fromArray($headers, null, 'A1');

        // Style the header row
        $headerStyle = $sheet->getStyle('A1:G1');
        $headerStyle->getFont()->setBold(true);
        $headerStyle->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FF4CAF50');
        $headerStyle->getFont()->getColor()->setARGB('FFFFFFFF');

        // Add data
        $row = 2;
        foreach ($records as $record) {
            $sheet->fromArray([
                $record->user_id ?? 'N/A',
                $record->user ? $record->user->first_name . ' ' . $record->user->last_name : $record->employee_name,
                $record->datetime->format('Y-m-d'),
                $record->datetime->format('H:i:s'),
                $record->site?->name ?? 'Unknown',
                $record->attendanceUpload?->original_filename ?? 'N/A',
                $record->attendance_upload_id,
            ], null, 'A' . $row);
            $row++;
        }

        // Auto-size columns
        foreach (range('A', 'G') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        // Create writer and output
        $writer = new Xlsx($spreadsheet);

        // Write to output buffer
        ob_start();
        $writer->save('php://output');
        $content = ob_get_clean();

        return response($content, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
