<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasSchedule
{
    /**
     * Handle an incoming request.
     *
     * Check if Agent or Team Lead users have an active schedule.
     * If not, redirect them to the schedule setup page.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Skip if no user or user is not approved yet
        // Let CheckUserApproved middleware handle unapproved users
        if (!$user || !$user->is_approved) {
            return $next($request);
        }

        // Only check for Agent and Team Lead roles
        if (in_array($user->role, ['Agent', 'Team Lead'])) {
            // Check if user has any schedule (not just active, to avoid re-prompting)
            $hasSchedule = $user->employeeSchedules()->exists();

            // Skip if already on the schedule setup page or other excluded routes
            $excludedRoutes = [
                'schedule-setup',
                'schedule-setup.store',
                'pending-approval',
                'logout',
            ];

            if (!$hasSchedule && !$request->routeIs($excludedRoutes)) {
                return redirect()->route('schedule-setup')
                    ->with('flash', [
                        'message' => 'Please complete your schedule setup before continuing.',
                        'type' => 'info'
                    ]);
            }
        }

        return $next($request);
    }
}
