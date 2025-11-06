<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Site;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AttendanceController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Attendance::with(['user', 'site']);

        // Search
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('employee_name', 'like', "%{$search}%")
                    ->orWhereHas('site', function ($q) use ($search) {
                        $q->where('name', 'like', "%{$search}%");
                    })
                    ->orWhere('status', 'like', "%{$search}%");
            });
        }

        // Filter by site
        if ($siteId = $request->query('site')) {
            $query->where('site_id', $siteId);
        }

        // Filter by status
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        // Filter by shift
        if ($shift = $request->query('shift')) {
            $query->where('shift', $shift);
        }

        // Filter by date
        if ($date = $request->query('date')) {
            $query->whereDate('time_in', $date);
        }

        $attendances = $query->orderBy('time_in', 'desc')->paginate(15);

        // Format data for frontend
        $formattedData = $attendances->through(function ($attendance) {
            return [
                'id' => $attendance->id,
                'employee_name' => $attendance->employee_name,
                'site' => $attendance->site?->name ?? 'N/A',
                'shift' => $attendance->shift,
                'status' => $attendance->status,
                'attended_at' => $attendance->time_in?->toISOString() ?? 'N/A',
            ];
        });

        // Get filter options
        $sites = Site::select('id', 'name')->orderBy('name')->get();
        $statuses = Attendance::distinct()->pluck('status')->filter()->values();
        $shifts = Attendance::distinct()->pluck('shift')->filter()->values();

        return Inertia::render('Attendance/Index', [
            'attendances' => $formattedData,
            'filters' => [
                'sites' => $sites,
                'statuses' => $statuses,
                'shifts' => $shifts,
            ],
        ]);
    }
}
