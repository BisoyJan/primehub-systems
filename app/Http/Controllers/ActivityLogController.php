<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ActivityLogController extends Controller
{
    /**
     * Sensitive fields that should never be displayed in activity log details.
     */
    private const SENSITIVE_FIELDS = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
    ];

    public function index(Request $request)
    {
        $search = $request->input('search');
        $event = $request->input('event');
        $causer = $request->input('causer');

        $query = Activity::with('causer', 'subject')
            ->orderBy('created_at', 'desc');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', "%{$search}%")
                    ->orWhere('log_name', 'like', "%{$search}%");
            });
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
            ->through(fn (Activity $activity) => [
                'id' => $activity->id,
                'description' => $activity->description,
                'event' => $activity->event,
                'subject_type' => $activity->subject_type ? class_basename($activity->subject_type) : null,
                'subject_id' => $activity->subject_id,
                'causer' => $activity->causer ? $activity->causer->name : 'System',
                'properties' => $this->sanitizeProperties($activity->properties?->toArray() ?? []),
                'created_at' => $activity->created_at->format('M d, Y h:i A'),
                'created_at_human' => $activity->created_at->diffForHumans(),
            ]);

        $causers = User::query()
            ->select('id', DB::raw("CONCAT(first_name, ' ', last_name) as name"))
            ->whereIn('id', Activity::distinct()->whereNotNull('causer_id')->pluck('causer_id'))
            ->orderBy('first_name')
            ->get()
            ->pluck('name', 'id');

        return Inertia::render('Admin/ActivityLogs/Index', [
            'activities' => $activities,
            'causers' => $causers,
            'filters' => [
                'search' => $search,
                'event' => $event,
                'causer' => $causer,
            ],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $search = $request->input('search');
        $event = $request->input('event');
        $causer = $request->input('causer');

        $query = Activity::with('causer')
            ->orderBy('created_at', 'desc');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', "%{$search}%")
                    ->orWhere('log_name', 'like', "%{$search}%");
            });
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

        $filename = 'activity_logs_'.now()->format('Y-m-d_His').'.csv';

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['ID', 'User', 'Event', 'Subject', 'Description', 'Changes', 'Date']);

            $query->chunk(500, function ($activities) use ($handle) {
                foreach ($activities as $activity) {
                    $properties = $this->sanitizeProperties($activity->properties?->toArray() ?? []);
                    $changes = $this->formatChangesForCsv($properties);

                    fputcsv($handle, [
                        $activity->id,
                        $activity->causer ? $activity->causer->name : 'System',
                        $activity->event,
                        class_basename($activity->subject_type).' #'.$activity->subject_id,
                        $activity->description,
                        $changes,
                        $activity->created_at->format('M d, Y h:i A'),
                    ]);
                }
            });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    /**
     * Remove sensitive fields from activity properties.
     *
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    private function sanitizeProperties(array $properties): array
    {
        foreach (['old', 'attributes'] as $key) {
            if (isset($properties[$key]) && is_array($properties[$key])) {
                $properties[$key] = array_diff_key(
                    $properties[$key],
                    array_flip(self::SENSITIVE_FIELDS)
                );
            }
        }

        return $properties;
    }

    /**
     * Format changes from properties into a readable CSV string.
     */
    private function formatChangesForCsv(array $properties): string
    {
        if (isset($properties['old'], $properties['attributes'])) {
            $changes = [];
            foreach ($properties['attributes'] as $key => $newValue) {
                $oldValue = $properties['old'][$key] ?? '—';
                $changes[] = "{$key}: {$oldValue} → {$newValue}";
            }

            return implode('; ', $changes);
        }

        if (isset($properties['attributes'])) {
            return 'Created: '.implode(', ', array_map(
                fn ($k, $v) => "{$k}: {$v}",
                array_keys($properties['attributes']),
                array_values($properties['attributes'])
            ));
        }

        return '';
    }
}
