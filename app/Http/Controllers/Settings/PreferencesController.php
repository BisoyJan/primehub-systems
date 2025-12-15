<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PreferencesController extends Controller
{
    /**
     * Show the preferences form.
     */
    public function edit(Request $request)
    {
        return Inertia::render('settings/preferences', [
            'user' => $request->user()->only(['time_format', 'inactivity_timeout']),
        ]);
    }

    /**
     * Update the user's preferences.
     */
    public function update(Request $request)
    {
        $validated = $request->validate([
            'time_format' => 'required|in:12,24',
            'inactivity_timeout' => 'nullable|integer|min:5|max:480', // 5 min to 8 hours
        ]);

        // Convert empty string to null for inactivity_timeout
        if (isset($validated['inactivity_timeout']) && $validated['inactivity_timeout'] === '') {
            $validated['inactivity_timeout'] = null;
        }

        $request->user()->update($validated);

        return redirect()->back()->with('success', 'Preferences updated successfully.');
    }
}
