<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class AttendanceToolsController extends Controller
{
    /**
     * Display the attendance tools hub page.
     * Groups operational tools: Reprocessing, Anomalies, Export, Uploads, Retention Policies.
     */
    public function index(): Response
    {
        return Inertia::render('Attendance/Tools/Index');
    }
}
