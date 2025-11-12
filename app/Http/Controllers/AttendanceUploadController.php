<?php

namespace App\Http\Controllers;

use App\Models\AttendanceUpload;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AttendanceUploadController extends Controller
{
    /**
     * Display a listing of recent attendance uploads
     */
    public function index()
    {
        $uploads = AttendanceUpload::with(['uploader:id,first_name,middle_name,last_name', 'biometricSite:id,name'])
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return Inertia::render('Attendance/Uploads/Index', [
            'uploads' => $uploads,
        ]);
    }

    /**
     * Display the specified upload with full details
     */
    public function show(AttendanceUpload $upload)
    {
        $upload->load(['uploader:id,first_name,middle_name,last_name', 'biometricSite:id,name']);

        return Inertia::render('Attendance/Uploads/Show', [
            'upload' => [
                'id' => $upload->id,
                'original_filename' => $upload->original_filename,
                'shift_date' => $upload->shift_date ? Carbon::parse($upload->shift_date)->format('Y-m-d') : null,
                'biometric_site' => $upload->biometricSite ? [
                    'id' => $upload->biometricSite->id,
                    'name' => $upload->biometricSite->name,
                ] : null,
                'total_records' => $upload->total_records,
                'processed_records' => $upload->processed_records,
                'matched_employees' => $upload->matched_employees,
                'unmatched_names' => $upload->unmatched_names,
                'unmatched_names_list' => $upload->unmatched_names_list,
                'date_warnings' => $upload->date_warnings,
                'dates_found' => $upload->dates_found,
                'status' => $upload->status,
                'notes' => $upload->notes,
                'error_message' => $upload->error_message,
                'uploaded_by' => $upload->uploader ? [
                    'id' => $upload->uploader->id,
                    'name' => $upload->uploader->name,
                ] : null,
                'created_at' => $upload->created_at->format('Y-m-d H:i:s'),
                'updated_at' => $upload->updated_at->format('Y-m-d H:i:s'),
            ],
        ]);
    }
}
