<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PreferencesController extends Controller
{
    /**
     * The default notification preferences when none are set.
     */
    private const DEFAULT_NOTIFICATION_PREFERENCES = [
        'leave_request' => true,
        'it_concern' => true,
        'medication_request' => true,
        'attendance_status' => true,
        'maintenance_due' => true,
        'pc_assignment' => true,
        'system' => true,
        'coaching_session' => true,
        'coaching_acknowledged' => true,
        'coaching_reviewed' => true,
        'coaching_ready_for_review' => true,
        'coaching_pending_reminder' => true,
        'coaching_unacknowledged_alert' => true,
        'break_overage' => true,
        'undertime_approval' => true,
        'account_deletion' => true,
        'account_reactivation' => true,
        'account_restored' => true,
    ];

    /**
     * Show the preferences form.
     */
    public function edit(Request $request)
    {
        $user = $request->user();

        return Inertia::render('settings/preferences', [
            'user' => $user->only(['inactivity_timeout']),
            'notificationPreferences' => array_merge(
                self::DEFAULT_NOTIFICATION_PREFERENCES,
                $user->notification_preferences ?? []
            ),
        ]);
    }

    /**
     * Update the user's preferences.
     */
    public function update(Request $request)
    {
        $validated = $request->validate([
            'inactivity_timeout' => 'nullable|integer|min:5|max:480',
            'notification_preferences' => 'nullable|array',
            'notification_preferences.*' => 'boolean',
        ]);

        // Convert empty string to null for inactivity_timeout
        if (isset($validated['inactivity_timeout']) && $validated['inactivity_timeout'] === '') {
            $validated['inactivity_timeout'] = null;
        }

        // Only store notification preferences if provided
        if (isset($validated['notification_preferences'])) {
            $allowedTypes = array_keys(self::DEFAULT_NOTIFICATION_PREFERENCES);
            $validated['notification_preferences'] = array_map(
                fn ($value) => (bool) $value,
                array_intersect_key(
                    $validated['notification_preferences'],
                    array_flip($allowedTypes)
                )
            );
        }

        $request->user()->update($validated);

        return redirect()->back()->with('success', 'Preferences updated successfully.');
    }
}
