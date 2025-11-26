<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;
use Inertia\Inertia;

class ActivityLogController extends Controller
{
    public function index(Request $request)
    {
        $search = $request->input('search');
        $event = $request->input('event');
        $causer = $request->input('causer');

        $query = Activity::with('causer', 'subject')
            ->orderBy('created_at', 'desc');

        if ($search) {
            $query->where('description', 'like', "%{$search}%")
                  ->orWhere('log_name', 'like', "%{$search}%");
        }

        if ($event) {
            $query->where('event', $event);
        }

        if ($causer) {
            $query->whereHas('causer', function ($q) use ($causer) {
                $q->where('first_name', 'like', "%{$causer}%")
                  ->orWhere('last_name', 'like', "%{$causer}%");
            });
        }

        $activities = $query->paginate(20)
            ->withQueryString()
            ->through(fn($activity) => [
                'id' => $activity->id,
                'description' => $activity->description,
                'event' => $activity->event,
                'subject_type' => class_basename($activity->subject_type),
                'subject_id' => $activity->subject_id,
                'causer' => $activity->causer ? $activity->causer->name : 'System',
                'properties' => $activity->properties,
                'created_at' => $activity->created_at->format('M d, Y h:i A'),
                'created_at_human' => $activity->created_at->diffForHumans(),
            ]);

        return Inertia::render('Admin/ActivityLogs/Index', [
            'activities' => $activities,
            'filters' => [
                'search' => $search,
                'event' => $event,
                'causer' => $causer,
            ]
        ]);
    }
}
