<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class UpdateLastActivity
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check()) {
            $timeout = config('session.inactivity_timeout', 15) * 60; // Convert minutes to seconds
            $lastActivity = $request->session()->get('last_activity_time');

            // Check if user has been inactive for too long
            if ($lastActivity && (time() - $lastActivity) > $timeout) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('login')->with([
                    'message' => 'You have been logged out due to inactivity.',
                    'type' => 'warning',
                ]);
            }

            // Update last activity time
            $request->session()->put('last_activity_time', time());
        }

        return $next($request);
    }
}
