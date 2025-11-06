<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class AttendanceController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Attendance/Index', [
            'attendances' => [
                'data' => [],
                'links' => [],
                'meta' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => 15,
                    'total' => 0,
                ],
            ],
            'filters' => [
                'sites' => [],
                'statuses' => [],
                'shifts' => [],
            ],
        ]);
    }
}
